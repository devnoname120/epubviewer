# Large CBZ and cached archive-module follow-up design

## Problem

Epubviewer 1.9.12 fixed the supplied large CBR only in the new URL-based RAR path, but the deployed reader never selected that path because the template exposes the file extension (`cbr`/`cbz`) while `cbr.js` compared it with MIME types. The original 281 MB CBR therefore still entered the 100 MiB whole-buffer downloader.

The same whole-buffer architecture rejects large CBZ files. The supplied Castlevania CBZ is 278,411,513 bytes, although its 132 entries and 280,314,013 decoded bytes are individually within the existing archive limits.

A second independent failure occurs after upgrading from 1.9.11. The versioned `bitjs.js` loader imports an unversioned `epubviewer-rar.mjs`, and both generated modules are cached for roughly six months. A browser retaining the 1.9.11 module cannot satisfy the new 1.9.12 `createRarUrlUnarchiver` named import, so archive initialization aborts and the UI reports “Archive support failed to load.”

Finally, Nextcloud 34 mishandles a `Range: bytes=0-0` request on these public DAV files by returning the complete file. Two-byte and other non-degenerate ranges are served correctly.

## Design

### Archive routing

The comic reader will recognize both extension values and MIME aliases:

- RAR: `cbr`, `application/x-cbr`, `application/comicbook+rar`
- ZIP: `cbz`, `application/x-cbz`, `application/comicbook+zip`

RAR always uses the bounded URL worker. ZIP retains the existing buffered BitJS path for publications at or below 100 MiB. When the download is rejected specifically because the declared or observed input exceeds that limit, the reader switches to a bounded ZIP URL worker. Other download failures remain errors and are not hidden by the fallback.

### Shared bounded range transport

The existing range reader becomes archive-format-neutral internally while retaining compatibility exports. Its opening probe requests bytes `0-1`, avoiding the Nextcloud `0-0` bug. It continues to require:

- same-origin URLs;
- an exact `206 Partial Content` response;
- the requested start and end offsets;
- a stable total archive size;
- identity content encoding;
- an exact `Content-Length` and body length;
- a bounded aggregate number of fetched bytes;
- immediate response-body cancellation on validation failure.

RAR and ZIP share the 1 GiB remote archive ceiling and the existing bounded transfer budget.

### ZIP range extractor

A dedicated extractor runs inside the existing archive worker and reads only required ZIP ranges:

1. Fetch at most the final 65,557 bytes and find the unique EOCD record.
2. Reject multi-disk and interactive ZIP64 archives, inconsistent offsets, excessive entry counts, and oversized central directories.
3. Fetch and parse the central directory before decoding any entry.
4. Reject encryption, unsupported compression methods, inconsistent local headers, unsafe sizes, excessive compression ratios, excessive aggregate output, and duplicate local offsets.
5. Fetch each selected entry’s local header and compressed data independently.
6. Decode method 0 directly and method 8 with the existing bounded BitJS inflater.
7. Verify exact output length and CRC-32 before emitting the entry.
8. Skip directories and Unix symbolic links.

The extractor emits the same start, extract, progress, finish, and error message contract as the existing RAR worker, so the reader UI does not gain a second event system.

### Cache-safe generated-module loading

`bitjs.js` will dynamically import `epubviewer-rar.mjs` with the query string from its own versioned URL. The generated archive module will propagate that query string to the worker URL. Consequently, template version changes invalidate the loader, generated archive module, and worker as one deployment unit.

The stable BitJS dependency graph remains unchanged. The generated archive module and worker, whose public contract changed in 1.9.12, are explicitly tied to the template revision so a stale 1.9.11 module can no longer be selected by a 1.9.12-or-later loader.

## Error handling

Resource-limit errors gain a stable machine-readable code while preserving their existing messages. The reader uses only the archive-input-limit code to select the ZIP range fallback. Network errors, malformed archives, integrity failures, and unsupported features remain visible and fail closed.

A range server that does not implement precise byte ranges can still open a small CBZ through the existing buffered path. A large CBZ on such a server is rejected rather than downloaded without bounds.

## Tests

Regression coverage will include:

- real `cbr` and `cbz` extension routing;
- the Nextcloud `bytes=0-0` quirk and the two-byte probe;
- cache-version propagation from `bitjs.js` to the generated module and worker;
- fallback only for the archive-input-limit error;
- sparse large-ZIP extraction without allocating the archive size;
- stored and deflated entries, data descriptors, UTF-8 and legacy names;
- CRC, local-header, central-directory, encryption, method, ratio, output, and range-budget failures;
- existing small CBZ, RAR3, RAR5, and PPMd paths.

The two public-share files will be exercised end to end in a fresh headless browser. Full JavaScript, PHP, static-analysis, lint, production-build, and release-package checks remain required before publication.
