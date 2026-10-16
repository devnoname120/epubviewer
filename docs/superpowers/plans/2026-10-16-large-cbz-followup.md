# Large CBZ and Cache-Safe Archive Loading Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make large CBR and CBZ publications open through bounded same-origin byte ranges while preventing stale generated archive modules from breaking upgraded installations.

**Architecture:** Keep the current buffered BitJS path for small ZIP publications, add a focused range-based ZIP extractor inside the existing archive worker for oversized CBZ files, and generalize the validated HTTP range transport shared with RAR. Tie the generated archive module to the template revision query and emit the worker with a content hash so stale contracts cannot collide.

**Tech Stack:** Browser ES modules, Web Workers, TypeScript, BitJS DEFLATE, Node.js test runner, Vite, PHP/Nextcloud templates.

---

## File map

- `js/reader-limits.js`: stable archive-input-limit code and remote ZIP limits.
- `src/archiveRangeReader.js`: format-neutral same-origin range transport with a two-byte opening probe.
- `src/rarRangeReader.js`: compatibility re-export for existing imports.
- `src/zipRangeExtractor.js`: EOCD/central-directory preflight, bounded entry reads, inflate, CRC validation, and extraction callbacks.
- `src/rarWorker.ts`: dispatch RAR and ZIP URL requests through one worker message contract.
- `src/rarUnarchiver.js`: generic URL-worker adapter plus RAR and ZIP factories using Vite's statically discoverable worker form.
- `src/rar.ts`: public exports for both URL factories.
- `js/cbrjs/bitjs.js`: query-preserving dynamic import of the generated archive bundle and CBRJS factory registration.
- `js/cbrjs/cbr.js`: real extension/MIME routing and ZIP range fallback only on an input-limit error.
- `tests/js/archive-range-reader.test.js`: transport and Nextcloud `0-0` regression coverage.
- `tests/js/zip-range-extractor.test.js`: sparse large-ZIP and integrity/limit coverage.
- `tests/js/rar-unarchiver.test.js`: ZIP URL adapter coverage.
- `tests/js/rar-worker-limits.test.js`: ZIP worker dispatch contract.
- `tests/js/cbrjs-webp.test.js`: extension routing and oversized-CBZ fallback behavior.
- `tests/js/archive-module-cache.test.js`: loader version propagation and content-hashed worker contract.

### Task 1: Stabilize range transport and archive-limit signaling

**Files:**
- Create: `tests/js/archive-range-reader.test.js`
- Modify: `tests/js/reader-limits.test.js`
- Modify: `js/reader-limits.js`
- Create: `src/archiveRangeReader.js`
- Modify: `src/rarRangeReader.js`

- [ ] **Step 1: Write the failing two-byte-probe and coded-error tests**

Move the existing range-reader tests to `archive-range-reader.test.js`, import both compatibility and generic factories, and assert the opening request is `bytes=0-1`:

```js
const reader = await createArchiveRangeReader(URL, options)
assert.equal(reader.length, total)
assert.deepEqual(requests.map(({ range }) => range), [
  'bytes=0-1',
  'bytes=254758226-254758233',
])
```

Add a server double that deliberately returns the entire archive for `bytes=0-0` and exact data for `bytes=0-1`; assert opening succeeds and `0-0` is never requested.

Add a `ReaderLimits.download()` test whose response declares `archiveBytes + 1`; assert rejection has both the existing message and `error.code === 'ARCHIVE_INPUT_LIMIT'`.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```sh
node --test tests/js/archive-range-reader.test.js tests/js/reader-limits.test.js
```

Expected: FAIL because the current reader requests `bytes=0-0`, `createArchiveRangeReader` does not exist, and limit errors have no code.

- [ ] **Step 3: Add the minimal generic transport and coded error**

In `js/reader-limits.js`, add:

```js
function fail(code, message) {
  var error = new Error(message)
  error.code = code
  throw error
}
```

Use `fail('ARCHIVE_INPUT_LIMIT', 'Publication download failed or exceeds the input limit.')` only for declared or streamed archive-size overflow. Preserve existing messages for every other failure.

Create `src/archiveRangeReader.js` by moving the validated reader logic and making diagnostics format-neutral. Open with:

```js
const probe = await request(0, 1)
archiveBytes = probe.total
for await (const _chunk of probe.stream) {}
```

Export `createArchiveRangeReader`. Make `src/rarRangeReader.js` re-export it as `createRarRangeReader` for compatibility:

```js
export { createArchiveRangeReader as createRarRangeReader } from './archiveRangeReader.js'
```

- [ ] **Step 4: Run the focused tests and verify GREEN**

Run the command from Step 2. Expected: PASS.

- [ ] **Step 5: Commit the transport boundary**

```sh
git add js/reader-limits.js src/archiveRangeReader.js src/rarRangeReader.js tests/js/archive-range-reader.test.js tests/js/reader-limits.test.js
git commit -m "fix: harden archive range probing"
```

### Task 2: Build the bounded ZIP range extractor

**Files:**
- Create: `src/zipRangeExtractor.js`
- Create: `tests/js/zip-range-extractor.test.js`

- [ ] **Step 1: Write a sparse ZIP test fixture and failing extraction tests**

Create a test helper that emits local headers, central headers, EOCD, optional data descriptors, and a reader backed by disjoint byte regions. The reader reports a synthetic length above 278 MB without allocating the gap:

```js
const source = sparseReader(278_411_513, [
  { offset: 0, bytes: localArea },
  { offset: 278_391_476, bytes: centralDirectory },
  { offset: 278_411_491, bytes: eocd },
])
```

Tests must cover:

```js
await extractZipRange(source, limits, {
  extract(entry) { extracted.push(entry) },
  progress(progress) { progressEvents.push(progress) },
})
```

Assert stored and deflated entries decode exactly, CRC is checked, directories and Unix symlinks are skipped, data descriptors are validated, and only represented ranges are read.

Add rejection tests for encrypted entries, unsupported methods, duplicate local offsets, inconsistent local metadata, bad CRC, oversized entry/aggregate output, excessive ratio, multi-disk/ZIP64 metadata, and ambiguous EOCD records.

- [ ] **Step 2: Run the extractor test and verify RED**

Run:

```sh
node --test tests/js/zip-range-extractor.test.js
```

Expected: FAIL because `src/zipRangeExtractor.js` does not exist.

- [ ] **Step 3: Implement EOCD and central-directory preflight**

Create `extractZipRange(source, limits, sink)` with helpers:

```js
const EOCD_SIGNATURE = 0x06054b50
const CENTRAL_SIGNATURE = 0x02014b50
const LOCAL_SIGNATURE = 0x04034b50
const DATA_DESCRIPTOR_SIGNATURE = 0x08074b50
const ZIP_TAIL_BYTES = 65_557

async function readExact(source, offset, length) {
  return limits.readStream(await source.read(offset, length), length)
}
```

Locate exactly one EOCD whose comment reaches archive end. Validate disk fields, non-ZIP64 sentinel values, entry count, directory size, and `directoryOffset + directorySize === eocdOffset`. Parse every central record into immutable metadata and call `limits.entry(uncompressed, compressed, total)` before reading local data.

- [ ] **Step 4: Implement bounded entry decode and integrity checks**

For each central entry:

```js
const localPrefix = await readExact(source, entry.localOffset, 30)
const nameAndExtra = await readExact(source, entry.localOffset + 30, nameLength + extraLength)
const compressed = await readExact(source, dataOffset, entry.compressedSize)
const output = entry.method === 0
  ? compressed
  : await inflate(compressed, entry.uncompressedSize)
limits.assert(output.byteLength === entry.uncompressedSize, 'ZIP output size differs from metadata.')
limits.assert(crc32(output) === entry.crc, 'ZIP entry CRC32 differs from metadata.')
```

If bit 3 is set, read and validate the 12- or 16-byte descriptor after compressed data. Normalize filename separators, reject encrypted entries and unsupported methods, and identify directories/symlinks from filename and external attributes.

Emit only after integrity validation:

```js
await sink.extract({ filename, fileData: output }, metadata)
await sink.progress({ compressedBytesRead, files })
```

- [ ] **Step 5: Run extractor tests and verify GREEN**

Run the command from Step 2. Expected: PASS.

- [ ] **Step 6: Commit the ZIP extractor**

```sh
git add src/zipRangeExtractor.js tests/js/zip-range-extractor.test.js
git commit -m "feat: extract large ZIP archives by range"
```

### Task 3: Integrate ZIP URL extraction into the worker adapter

**Files:**
- Modify: `src/rarWorker.ts`
- Modify: `src/rarUnarchiver.js`
- Modify: `src/rar.ts`
- Modify: `tests/js/rar-worker-limits.test.js`
- Modify: `tests/js/rar-unarchiver.test.js`

- [ ] **Step 1: Write failing worker and adapter tests**

Add an `extract-zip-url` worker test whose fake range reader and ZIP extractor record their arguments. Assert the worker posts start/extract/progress/finish messages and uses `zipArchiveBytes`/`zipFetchBytes`.

Add an adapter test:

```js
const unarchiver = createZipUrlUnarchiver('/large.cbz', {
  origin: ORIGIN,
  workerFactory: () => worker,
})
await unarchiver.start()
assert.deepEqual(worker.posted[0], {
  message: { type: 'extract-zip-url', url: `${ORIGIN}/large.cbz` },
  transfer: [],
})
```

Add a cache-contract test that requires the Vite-statically-discoverable `new Worker(new URL(...))` form and a worker output name containing `[hash]`.

- [ ] **Step 2: Run focused tests and verify RED**

Run:

```sh
node --test tests/js/rar-worker-limits.test.js tests/js/rar-unarchiver.test.js
```

Expected: FAIL because ZIP request type/factory and hashed worker output are absent.

- [ ] **Step 3: Add ZIP request dispatch**

Extend the request union:

```ts
interface ExtractZipUrlRequest {
  type: 'extract-zip-url'
  url: string
}
```

For ZIP, create the generic range reader with ZIP policy limits, send start, call `extractZipRange`, transfer each decoded entry, then send finish metadata `{ backend: 'bounded ZIP ranges', totalBytes, totalFiles }`.

- [ ] **Step 4: Generalize the URL unarchiver and preserve Vite worker discovery**

Keep the worker expression statically analyzable by Vite:

```js
return new Worker(new URL('./rarWorker.ts', import.meta.url), {
  name: 'epubviewer-archive',
  type: 'module',
})
```

Set `worker.rollupOptions.output.entryFileNames` to `js/epubviewer-rar-worker-[hash].mjs` in `vite.config.ts`.

Add a shared same-origin URL factory and export:

```js
export function createZipUrlUnarchiver(input, options = {}) {
  return createUrlUnarchiver('extract-zip-url', input, options)
}
```

Keep `createRarUrlUnarchiver` behavior unchanged and export the ZIP factory from `src/rar.ts`.

- [ ] **Step 5: Run focused tests and verify GREEN**

Run the command from Step 2. Expected: PASS.

- [ ] **Step 6: Commit worker integration**

```sh
git add src/rarWorker.ts src/rarUnarchiver.js src/rar.ts tests/js/rar-worker-limits.test.js tests/js/rar-unarchiver.test.js
git commit -m "feat: route large CBZ files through archive worker"
```

### Task 4: Fix real reader routing and stale module loading

**Files:**
- Modify: `js/cbrjs/bitjs.js`
- Modify: `js/cbrjs/cbr.js`
- Modify: `tests/js/cbrjs-webp.test.js`
- Create: `tests/js/archive-module-cache.test.js`

- [ ] **Step 1: Write failing extension-routing and fallback tests**

Change the existing RAR test to use `format: 'cbr'` and a `.cbr` URL. Add a CBZ URL-fallback test where `ReaderLimits.download` rejects an error with `code = 'ARCHIVE_INPUT_LIMIT'`; assert `getZipUnarchiver(url)` is called and no generic error is shown. Add a non-limit rejection test and assert ZIP URL fallback is not used.

Add a cache-contract test that reads `bitjs.js` and asserts it imports the archive bundle dynamically after copying `new URL(import.meta.url).search`, rather than using a static named import. Assert the generated worker uses a content-hashed filename through Vite.

- [ ] **Step 2: Run focused tests and verify RED**

Run:

```sh
node --test tests/js/cbrjs-webp.test.js tests/js/archive-module-cache.test.js
```

Expected: FAIL because `cbr` does not select RAR, CBZ has no URL fallback, and the loader uses a static unversioned import.

- [ ] **Step 3: Implement query-preserving module initialization**

Replace the static generated-module import with:

```js
const archiveModuleUrl = new URL('../epubviewer-rar.mjs', import.meta.url)
archiveModuleUrl.search = new URL(import.meta.url).search
const archiveModule = await import(archiveModuleUrl.href)
```

Register `getUnarchiver`, `getRarUnarchiver`, and `getZipUnarchiver` after the import resolves. Export no new global state.

- [ ] **Step 4: Implement real format routing and bounded CBZ fallback**

Use explicit format sets:

```js
var rangeRar = ['cbr', 'application/x-cbr', 'application/comicbook+rar'].indexOf(format) !== -1
var rangeZip = ['cbz', 'application/x-cbz', 'application/comicbook+zip'].indexOf(format) !== -1
```

Keep direct RAR URL routing. In the buffered download rejection handler:

```js
if (rangeZip && error && error.code === 'ARCHIVE_INPUT_LIMIT') {
  startUnarchiver(CBRJS.getZipUnarchiver(url))
  return
}
failArchive(error)
```

- [ ] **Step 5: Run focused tests and verify GREEN**

Run the command from Step 2. Expected: PASS.

- [ ] **Step 6: Commit reader routing and cache safety**

```sh
git add js/cbrjs/bitjs.js js/cbrjs/cbr.js tests/js/cbrjs-webp.test.js tests/js/archive-module-cache.test.js
git commit -m "fix: load archive backends cache-safely"
```

### Task 5: Verify supplied publications and complete release

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `appinfo/info.xml`
- Modify: `composer.json`
- Modify generated lock/version files through the existing bump script.

- [ ] **Step 1: Run focused and full local verification**

Run:

```sh
npm test
npm run lint
npm run stylelint
npm run lint-github-actions
npm run build
php vendor/bin/phpunit -c tests/phpunit.xml
php composer.phar run cs:check
php composer.phar run psalm
php composer.phar run psalm-security
```

Expected: all checks pass; documented third-party deprecation notices are acceptable only if exit status is zero.

- [ ] **Step 2: Exercise the public-share files in fresh headless browsers**

Open the original Wild Arms CBR, Final Fantasy III CBZ, and Castlevania CBZ with separate temporary Chrome profiles. Assert:

- no archive error message;
- page counts 133, 188, and 132 respectively;
- the CBR uses bounded ranges and exactly one libarchive fallback;
- the 278 MB CBZ does not issue a full-file response;
- a simulated stale 1.9.11 archive module cannot be selected because the module request includes the current revision and its worker URL is content-hashed.

- [ ] **Step 3: Update release metadata and changelog**

Add a fixed section describing real extension routing, large-CBZ ranges, the two-byte Nextcloud probe, and cache-safe module/worker loading. Bump the patch version consistently with `scripts/bump-version.sh` and regenerate lock metadata.

- [ ] **Step 4: Re-run the full verification and package inspection**

Build the release from a clean exported tree. Verify the tarball contains the generated archive worker, Wasm module, redistribution notices, and correct app version. Record byte size and SHA-256.

- [ ] **Step 5: Commit, push, open and merge a PR after CI**

```sh
git add CHANGELOG.md appinfo/info.xml composer.json composer.lock package.json package-lock.json
git commit -m "Prepare 1.9.13 release"
git push -u origin fix/issue-50-cbz-followup
gh pr create --repo devnoname120/epubviewer --base master --head fix/issue-50-cbz-followup
gh pr checks --watch <number>
gh pr merge --merge --delete-branch <number>
```

- [ ] **Step 6: Publish and report**

Create the release tag on the verified merge commit, wait for the App Store workflow, redownload and inspect the published archive, then comment on issue #50 with root causes, verification evidence, PR, release, and App Store links. Close the issue only after publication succeeds.
