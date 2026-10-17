# EPUB Text Highlights Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add colored, server-persisted EPUB text highlights with stable paired-CFI anchors, bounded rendering, recoloring, navigation, and deletion.

**Architecture:** Register a new Vite-built `EPUBJS.reader.plugins.HighlightsController` rather than editing the minified legacy reader. Split the feature into a pure model/CFI layer, an optimistic persistence repository with rollback, and a renderer that prefers CSS Custom Highlights but falls back to non-mutating rectangle overlays. Reuse the existing bookmark endpoint and schema by storing the start CFI in `value` and the complete bounded highlight object in `content`.

**Tech Stack:** Browser ES modules, legacy epub.js globals, CSS Custom Highlight API, DOM Range/CFI APIs, jQuery promises, Vite, Node.js test runner, PHP/Nextcloud bookmark persistence.

---

## File map

- `js/reader-limits.js`: add highlight count, CFI, excerpt, serialized-content, and fallback-rectangle limits.
- `src/epubHighlights/model.js`: fixed palette, annotation validation, text-range normalization, paired point-CFI creation/restoration, ordering, and serialization bounds.
- `src/epubHighlights/persistence.js`: serialize create/update/delete operations per highlight, update `reader.settings.annotations`, call the existing session endpoints, and roll back failures.
- `src/epubHighlights/renderer.js`: render current-chapter ranges through CSS Custom Highlights or bounded overlays without modifying EPUB text nodes.
- `src/epubHighlights/controller.js`: bind selection/chapter/reflow events, drive the toolbar and Highlights sidebar, and expose the controller show/hide contract.
- `src/epubHighlights/index.js`: register the controller as an epub.js reader plugin before reader startup.
- `js/epubjs/css/highlights.css`: outer-reader toolbar/sidebar styles and iframe fallback classes.
- `templates/epubviewer.php`: load the stylesheet/module and add accessible toolbar/sidebar markup.
- `vite.config.ts`: emit `js/epubviewer-epub-highlights.mjs` as a stable versioned entry.
- `tests/js/epub-highlight-model.test.js`: model, CFI, bounds, malformed state, and ordering coverage.
- `tests/js/epub-highlight-persistence.test.js`: create/update/delete success, sequencing, and rollback coverage.
- `tests/js/epub-highlight-renderer.test.js`: native registry, fallback geometry, chapter filtering, cleanup, and rectangle limits.
- `tests/js/epub-highlight-controller.test.js`: plugin registration, selection flow, exact-range recoloring, sidebar navigation/deletion, and plain-text rendering.
- `tests/js/archive-module-cache.test.js`: build/template contract for the new versioned module.
- `tests/Unit/ReaderStateEncodingTest.php`: bookmark JSON round-trip coverage for highlight content near the schema bounds.
- `README.md`, `CHANGELOG.md`, `appinfo/info.xml`, `composer.json`, `composer.lock`, `package.json`, `package-lock.json`: user documentation and release metadata.

### Task 1: Add the bounded highlight model and paired-CFI helpers

**Files:**
- Modify: `js/reader-limits.js`
- Create: `src/epubHighlights/model.js`
- Create: `tests/js/epub-highlight-model.test.js`

- [ ] **Step 1: Write failing policy and annotation-validation tests**

Create tests that require these policy values and reject every malformed field:

```js
assert.equal(ReaderLimits.policy.highlights, 1000)
assert.equal(ReaderLimits.policy.highlightCfiCharacters, 480)
assert.equal(ReaderLimits.policy.highlightTextCharacters, 1024)
assert.equal(ReaderLimits.policy.highlightContentBytes, 3900)
assert.equal(ReaderLimits.policy.highlightRectangles, 4096)

assert.equal(normalizeHighlight(validHighlight).color, 'yellow')
assert.equal(normalizeHighlight({ ...validHighlight, color: 'url(javascript:1)' }), null)
assert.equal(normalizeHighlight({ ...validHighlight, anchor: { ...anchor, start: 'x'.repeat(481) } }), null)
assert.equal(normalizeHighlight({ ...validHighlight, body: 'x'.repeat(1025) }), null)
```

Use a fixed palette export:

```js
export const HIGHLIGHT_PALETTE = Object.freeze({
  yellow: '#f6d94a',
  green: '#75d69c',
  blue: '#73b8f4',
  pink: '#f29ac2',
  orange: '#f4a261',
})
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```sh
node --test tests/js/epub-highlight-model.test.js
```

Expected: FAIL because the policy fields and model module do not exist.

- [ ] **Step 3: Add highlight-specific reader limits**

Extend the frozen policy in `js/reader-limits.js`:

```js
highlights: 1000,
highlightCfiCharacters: 480,
highlightTextCharacters: 1024,
highlightContentBytes: 3900,
highlightRectangles: 4096,
```

Do not widen existing publication, image, or text limits.

- [ ] **Step 4: Implement immutable validation and serialization helpers**

In `src/epubHighlights/model.js`, export:

```js
export function normalizeHighlight(value, policy = globalThis.ReaderLimits?.policy)
export function highlightContentBytes(value)
export function excerptSelection(text, maximum)
export function sameHighlightAnchor(left, right)
export function compareHighlights(left, right, EpubCFI)
```

`normalizeHighlight` must return a fresh frozen-safe plain object or `null`. It must require:

```js
{
  id: safeAsciiRouteSegmentAtMost(128),
  type: 'highlight',
  date: finiteNonNegativeNumber,
  edited: finiteNonNegativeNumber,
  anchor: {
    version: 1,
    spinePos: nonNegativeSafeInteger,
    start: cfiStringAtMost(policy.highlightCfiCharacters),
    end: cfiStringAtMost(policy.highlightCfiCharacters),
  },
  body: stringAtMost(policy.highlightTextCharacters),
  color: ownKeyOf(HIGHLIGHT_PALETTE),
}
```

Require `highlightContentBytes(normalized) <= policy.highlightContentBytes`. Count UTF-8 bytes with `TextEncoder`, not JavaScript code units.

- [ ] **Step 5: Write failing paired-CFI boundary tests**

Use a tiny fake document tree and a fake `EPUBJS.EpubCFI` adapter to cover:

```js
const anchor = rangeToHighlightAnchor(range, chapter, EpubCFI)
assert.deepEqual(anchor, {
  version: 1,
  spinePos: 2,
  start: 'epubcfi(start)',
  end: 'epubcfi(end)',
})

const restored = highlightAnchorToRange(anchor, document, EpubCFI)
assert.equal(restored.toString(), 'selected text')
```

Add a boundary whose end offset equals `textNode.length`; assert restoration keeps the exact end rather than moving one character backward. Add element-boundary selections spanning multiple text nodes and an inverted/missing endpoint rejection.

- [ ] **Step 6: Implement text-boundary normalization and exact point resolution**

Export:

```js
export function rangeToHighlightAnchor(range, chapter, EpubCFI)
export function highlightAnchorToRange(anchor, document, EpubCFI)
```

`rangeToHighlightAnchor` must:

1. reject collapsed/empty/over-limit selections;
2. locate the first and last intersecting text nodes with a `TreeWalker`;
3. derive local offsets without including unselected text;
4. call `generateCfiFromTextNode` for each point;
5. record `chapter.spinePos` and enforce CFI lengths.

`highlightAnchorToRange` must parse each CFI, use `findParent(parsed, document)` plus the final raw `childNodes` text-step index used by the bundled generator to obtain the exact text node, clamp only to the node length, create a new DOM range, and reject a collapsed or reversed result. Do not use the bundled `generateRangeFromCfi` for endpoint offsets.

- [ ] **Step 7: Run focused tests and verify GREEN**

Run:

```sh
node --test tests/js/epub-highlight-model.test.js tests/js/reader-limits.test.js
```

Expected: all model and existing limit tests pass.

- [ ] **Step 8: Commit the model boundary**

```sh
git add js/reader-limits.js src/epubHighlights/model.js tests/js/epub-highlight-model.test.js tests/js/reader-limits.test.js
git commit -m "feat: model bounded EPUB highlights"
```

### Task 2: Add rollback-safe highlight persistence

**Files:**
- Create: `src/epubHighlights/persistence.js`
- Create: `tests/js/epub-highlight-persistence.test.js`

- [ ] **Step 1: Write failing create/update/delete tests**

Construct a fake annotations object and session methods returning controllable thenables. Assert:

```js
await repository.create(highlight)
assert.deepEqual(session.setCalls[0], {
  name: highlight.id,
  value: highlight.anchor.start,
  type: 'highlight',
  content: highlight,
})
assert.equal(annotations[highlight.id].color, 'yellow')
```

Cover:

- count-limit rejection before mutation;
- create failure removes the optimistic record;
- recolor failure restores the prior color and `edited` timestamp;
- delete failure restores the record;
- two recolors for one ID are sent in order;
- operations for different IDs do not block each other;
- callback notifications receive `saving`, `saved`, and `error` states.

- [ ] **Step 2: Run the persistence test and verify RED**

Run:

```sh
node --test tests/js/epub-highlight-persistence.test.js
```

Expected: FAIL because `HighlightRepository` does not exist.

- [ ] **Step 3: Implement a per-ID serialized repository**

Export:

```js
export class HighlightRepository {
  constructor({ annotations, session, policy, onChange, onStatus })
  list()
  create(highlight)
  update(id, changes)
  remove(id)
}
```

Use a `Map` of per-ID promise tails:

```js
#enqueue(id, operation) {
  const previous = this.pending.get(id) ?? Promise.resolve()
  const current = previous.catch(() => {}).then(operation)
  this.pending.set(id, current.finally(() => {
    if (this.pending.get(id) === current) this.pending.delete(id)
  }))
  return current
}
```

Adapt jQuery Deferreds with a single promise helper that supplies both fulfillment and rejection callbacks to `.then`.

For writes, call:

```js
session.setBookmark(
  highlight.id,
  highlight.anchor.start,
  'highlight',
  highlight,
)
```

Mutate `annotations` optimistically, call `onChange`, and roll back on rejection. Deep-clone the previous normalized record before update/delete rollback.

- [ ] **Step 4: Run persistence tests and verify GREEN**

Run the command from Step 2. Expected: PASS.

- [ ] **Step 5: Commit persistence**

```sh
git add src/epubHighlights/persistence.js tests/js/epub-highlight-persistence.test.js
git commit -m "feat: persist EPUB highlights safely"
```

### Task 3: Render highlights without mutating publication text

**Files:**
- Create: `src/epubHighlights/renderer.js`
- Create: `tests/js/epub-highlight-renderer.test.js`

- [ ] **Step 1: Write failing native-renderer tests**

Use a fake iframe window exposing `CSS.highlights` and `Highlight`. Resolve two yellow and one blue annotation ranges, then assert:

```js
renderer.render(document, chapter, annotations)
assert.equal(registry.get('epubviewer-yellow').size, 2)
assert.equal(registry.get('epubviewer-blue').size, 1)
assert.match(injectedStyle.textContent, /::highlight\(epubviewer-yellow\)/)
```

Assert annotations from other spine positions and malformed anchors are skipped, `clear()` deletes only `epubviewer-*` registry names, and a second render replaces rather than accumulates ranges.

- [ ] **Step 2: Write failing fallback-overlay tests**

Without `CSS.highlights`, return deterministic `getClientRects()` values and a renderer page offset. Assert overlay positions use:

```js
left = rect.left + renderer.render.leftPos
top = rect.top + iframeWindow.scrollY
```

Require pointer-transparent overlays, palette-only classes, cleanup between renders, and rejection when cumulative rectangles exceed `policy.highlightRectangles`.

- [ ] **Step 3: Run renderer tests and verify RED**

Run:

```sh
node --test tests/js/epub-highlight-renderer.test.js
```

Expected: FAIL because the renderer module is absent.

- [ ] **Step 4: Implement native CSS Custom Highlight rendering**

Export:

```js
export class EpubHighlightRenderer {
  constructor({ EpubCFI, palette, policy, onError })
  render(document, chapter, renderer, highlights)
  schedule(document, chapter, renderer, highlights)
  clear()
}
```

For the native path:

- inject one idempotent style element into the chapter head;
- create one `new iframeWindow.Highlight()` per palette key;
- add validated ranges with `highlight.add(range)`;
- register under `epubviewer-${color}`;
- keep registry names so `clear()` does not touch unrelated page highlights.

- [ ] **Step 5: Implement the bounded overlay fallback**

Create a single `div.epubviewer-highlight-layer.reader_generated` under the chapter body. For each range rectangle, create a child with a palette class and numeric `left`, `top`, `width`, and `height`. Skip zero-area rectangles and stop with a visible controller error before exceeding the rectangle limit.

`schedule()` must coalesce repeated page/reflow events into one animation-frame render and cancel any pending frame in `clear()`.

- [ ] **Step 6: Run renderer tests and verify GREEN**

Run the command from Step 3. Expected: PASS.

- [ ] **Step 7: Commit rendering**

```sh
git add src/epubHighlights/renderer.js tests/js/epub-highlight-renderer.test.js
git commit -m "feat: render EPUB highlights without DOM mutation"
```

### Task 4: Integrate selection, toolbar, and Highlights sidebar

**Files:**
- Create: `src/epubHighlights/index.js`
- Create: `js/epubjs/css/highlights.css`
- Modify: `templates/epubviewer.php`
- Modify: `vite.config.ts`
- Create: `tests/js/epub-highlight-controller.test.js`
- Modify: `tests/js/archive-module-cache.test.js`

- [ ] **Step 1: Add failing build/template contract tests**

Assert the Vite input contains a quoted `epub-highlights` entry and the EPUB template loads, in order:

```text
reader.min.js
js/epubviewer-epub-highlights.mjs
js/epubviewer-ready.mjs
```

Assert the template contains:

```html
<button id="show-Highlights" data-view="Highlights">
<div id="highlightsView" class="highlights-view view">
<div id="epub-highlight-toolbar" role="toolbar" hidden>
```

and exactly the five `data-highlight-color` palette keys.

- [ ] **Step 2: Add failing controller interaction tests**

Build minimal fake reader/book/renderer/session objects and assert:

- `EPUBJS.reader.plugins.HighlightsController` is registered;
- a collapsed selection hides the toolbar;
- a multi-node selection captures an anchor/excerpt and positions the toolbar within the outer viewport;
- clicking yellow creates one persisted record and clears the browser selection;
- selecting the exact anchor and clicking blue updates the existing record rather than adding a second;
- sidebar excerpts use `textContent`, never `innerHTML`;
- clicking an item calls `book.gotoCfi(start)`;
- recoloring and deletion call the repository and refresh rendering;
- Escape, chapter changes, and outside pointer events hide the toolbar;
- malformed restored highlights appear as unavailable but do not break the panel.

- [ ] **Step 3: Run controller/contract tests and verify RED**

Run:

```sh
node --test tests/js/epub-highlight-controller.test.js tests/js/archive-module-cache.test.js
```

Expected: FAIL because the entry, markup, and plugin are absent.

- [ ] **Step 4: Add the module entry and accessible markup**

In `vite.config.ts` add:

```ts
'epub-highlights': path.resolve(import.meta.dirname, 'src/epubHighlights/index.js'),
```

In `templates/epubviewer.php`:

- link `js/epubjs/css/highlights.css` with the existing version query;
- add the Highlights sidebar button after Notes;
- add a Highlights panel with `<ol id="highlights">` and `#highlight-status[aria-live="polite"]`;
- add a hidden toolbar with five buttons carrying `data-highlight-color` and accessible color labels;
- load `js/epubviewer-epub-highlights.mjs` as a module after `reader.min.js` and before `epubviewer-ready.mjs`.

- [ ] **Step 5: Implement the epub.js plugin controller**

At module load, require only the legacy globals that already exist:

```js
const EPUBJS = globalThis.EPUBJS
if (EPUBJS?.reader?.plugins) {
  EPUBJS.reader.plugins.HighlightsController = function (book) {
    return createHighlightsController({ reader: this, book, document, window })
  }
}
```

The controller must:

1. normalize restored `type === 'highlight'` records;
2. instantiate `HighlightRepository` and `EpubHighlightRenderer`;
3. register a `beforeChapterDisplay` hook;
4. listen to `renderer:selected`, `renderer:locationChanged`, `renderer:resized`, and page unload;
5. capture a cloned range before toolbar focus clears the iframe selection;
6. create or recolor an exact-anchor highlight;
7. rebuild sorted sidebar entries after every mutation;
8. implement `{ show, hide }` for `SidebarController`;
9. clear renderer state and listeners on unload.

Toolbar position must use the EPUB iframe rectangle plus the selected range rectangle and clamp within the outer viewport. Do not place publication text into attributes or HTML.

- [ ] **Step 6: Style the toolbar, panel, and fallback overlays**

`highlights.css` must include:

- an outer fixed toolbar above the EPUB iframe with keyboard-visible focus;
- 32–44 px color targets with text-independent labels;
- a compact sidebar list with excerpt truncation, color selector, link, delete button, and failure state;
- iframe-injected fallback classes whose overlays use `position:absolute`, `pointer-events:none`, and bounded opacity;
- dark/night-mode-safe borders and selected states.

Avoid inline event handlers and arbitrary persisted color values.

- [ ] **Step 7: Run controller/contract tests and verify GREEN**

Run the command from Step 3. Expected: PASS.

- [ ] **Step 8: Run the complete JavaScript suite and production build**

Run:

```sh
npm test
npm run lint
npm run stylelint
npm run build
```

Expected: all tests/lints pass and Vite emits `js/epubviewer-epub-highlights.mjs`.

- [ ] **Step 9: Commit integration**

```sh
git add src/epubHighlights/index.js js/epubjs/css/highlights.css templates/epubviewer.php vite.config.ts tests/js/epub-highlight-controller.test.js tests/js/archive-module-cache.test.js
git commit -m "feat: add persistent EPUB highlight controls"
```

### Task 5: Verify server round-tripping and browser behavior

**Files:**
- Modify: `tests/Unit/ReaderStateEncodingTest.php`
- Optional temporary diagnostic: `safe-script-issue48-browser-e2e.mjs` (delete before commit)

- [ ] **Step 1: Add a PHP highlight round-trip regression**

Create a bookmark entity with:

```php
$content = [
    'id' => 'highlight-test',
    'type' => 'highlight',
    'date' => 1,
    'edited' => 2,
    'anchor' => [
        'version' => 1,
        'spinePos' => 0,
        'start' => 'epubcfi(/6/2!/4/2/1:0)',
        'end' => 'epubcfi(/6/2!/4/2/1:12)',
    ],
    'body' => '<script>literal text</script>',
    'color' => 'yellow',
];
```

Assert `Bookmark::toService()` returns the exact nested JSON data and preserves the body as literal text. Assert a serialized record at 3,900 bytes round-trips while one exceeding the client bound is rejected by the JavaScript model test.

- [ ] **Step 2: Run PHP tests and verify GREEN**

Run:

```sh
php vendor/bin/phpunit --bootstrap tests/bootstrap-unit.php --configuration tests/phpunit.xml
```

Expected: all tests pass with the existing environment-dependent skips only.

- [ ] **Step 3: Exercise a production browser against a multi-node EPUB fixture**

Build production assets and launch a fresh Chrome profile with a bounded local HTTP server. The harness must serve:

- the production reader assets;
- an EPUB chapter containing a selection spanning multiple inline elements;
- a fake bookmark endpoint that records POST/DELETE payloads and replays them after reload.

Automate these assertions through CDP:

1. select text spanning at least two text nodes;
2. create a yellow highlight;
3. create a blue highlight in another chapter;
4. change font size and page width;
5. navigate away and back;
6. reload with a fresh page instance;
7. verify exact restored text/color and no duplicate records;
8. recolor yellow to green;
9. delete blue;
10. verify endpoint payloads use start CFI in `value`, full object in `content`, and CSRF-bearing requests from `ready.ts`;
11. confirm no console errors, script injection, or mutated publication text nodes.

Delete the temporary browser script after recording the results.

- [ ] **Step 4: Run full local verification**

Run:

```sh
npm test
npm run lint
npm run stylelint
npm run lint-github-actions
npm run build
php vendor/bin/phpunit --bootstrap tests/bootstrap-unit.php --configuration tests/phpunit.xml
php composer.phar run cs:check
php composer.phar run lint
php composer.phar run psalm:ci -- --monochrome --no-progress
php composer.phar run psalm -- --monochrome --no-progress --taint-analysis
xmllint --noout --schema nextcloud-server/resources/app-info.xsd appinfo/info.xml
```

Expected: zero failures. Composer/PHP deprecation notices are acceptable only with exit status zero.

- [ ] **Step 5: Commit server regression coverage**

```sh
git add tests/Unit/ReaderStateEncodingTest.php
git commit -m "test: cover persisted EPUB highlights"
```

### Task 6: Document, package, merge, and release

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `appinfo/info.xml`
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `package.json`
- Modify: `package-lock.json`

- [ ] **Step 1: Document the feature and operational limits**

Add a README section explaining:

- select EPUB text and choose one of five colors;
- highlights are stored per user/file in Nextcloud and synchronize across sessions;
- the Highlights sidebar navigates, recolors, and deletes records;
- limits are 1,000 records, 480-character endpoint CFIs, 1,024-character excerpts, and 3,900-byte serialized content;
- highlights do not modify the EPUB file.

- [ ] **Step 2: Bump to 1.9.15 and replace the bump-script boilerplate**

Run `./scripts/bump-version.sh 1.9.15`. Add a `### Added` entry for colored persistent EPUB highlights and a `### Security` entry for bounded validation/non-mutating rendering. Ensure no dependency version drifts merely because `composer update` found a newer package; restore unrelated lock entries while retaining the new root content hash.

- [ ] **Step 3: Re-run full verification and clean exported-tree packaging**

Repeat Task 5 Step 4. Export `HEAD` to a temporary tree, install locked dependencies, build, and create the app-store tarball. Verify:

- app version matches all metadata;
- `js/epubviewer-epub-highlights.mjs` and `js/epubjs/css/highlights.css` exist;
- no `src`, tests, node modules, temporary scripts, or VCS files are included;
- the archive contains the existing worker, Wasm decoder, and redistribution notices;
- record archive byte size and SHA-256.

- [ ] **Step 4: Commit and push**

```sh
git add README.md CHANGELOG.md appinfo/info.xml composer.json composer.lock package.json package-lock.json
git commit -m "Prepare 1.9.15 release"
git push -u origin feature/issue-48-epub-highlights
```

- [ ] **Step 5: Open PR, wait for all checks, and merge**

Use `gh` against `devnoname120/epubviewer`:

```sh
gh pr create --repo devnoname120/epubviewer --base master --head feature/issue-48-epub-highlights --title "Add persistent EPUB text highlights" --body-file /tmp/epubviewer-pr-48.md
gh pr checks --watch <number>
gh pr merge <number> --repo devnoname120/epubviewer --merge --delete-branch
```

Before merging, require no failed/pending checks and verify the PR head SHA equals the fully tested release commit.

- [ ] **Step 6: Publish and independently verify the release**

Create the release tag on the verified merge commit with the changelog body. Wait for the Release App Store workflow, then re-download the attached tarball and verify its digest, version, highlight module/CSS, existing Wasm/worker/notices, and absence of development files. Confirm the Nextcloud App Store page lists the new version.

- [ ] **Step 7: Update and close issue #48**

Post a technical issue comment containing:

- the paired-CFI/no-DOM-mutation architecture;
- available colors and sidebar operations;
- persistence and rollback behavior;
- automated/browser verification evidence;
- PR, release, workflow, and App Store links;
- published artifact byte size and SHA-256.

Close issue #48 only after the release and App Store publication are verified.
