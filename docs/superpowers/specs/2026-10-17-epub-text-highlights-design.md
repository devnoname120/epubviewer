# EPUB text highlighting design

## Problem

Issue #48 requests colored text highlighting in EPUB publications with permanent storage. The EPUB reader already persists bookmarks and notes through `reader_bookmarks`, but the bundled epub.js release cannot restore CFI range strings: it can generate a range CFI, yet its parser explicitly treats ranges as unsupported. A robust implementation therefore cannot store a single range CFI or mutate the EPUB DOM in a way that changes later CFI paths.

## Requirements

- A user can select non-empty text in the currently rendered EPUB chapter and choose a highlight color.
- Highlights survive reloads, browser restarts, reflow, font changes, and chapter navigation.
- A user can navigate to, recolor, and delete saved highlights.
- Existing bookmarks and notes remain unchanged.
- Highlight rendering must not modify publication text nodes or make future anchors depend on generated markup.
- Persisted data and UI text are treated as untrusted input.
- The feature remains bounded under malformed or excessive saved state.

## Considered approaches

### 1. Existing annotation storage with paired point CFIs — selected

Store a new `highlight` annotation type through the existing bookmark API. Each highlight records two point CFIs, the chapter spine position, a palette key, and a bounded text excerpt. Render ranges without mutating publication content.

This reuses the authenticated Nextcloud persistence path, requires no schema migration, and works around the bundled epub.js range-CFI limitation.

### 2. Embed markup into the EPUB

Editing chapter XHTML or the EPUB archive would make highlights portable with the file, but it changes user content, requires archive rewriting and conflict handling, invalidates signatures, and creates a substantially larger security boundary. This is out of scope.

### 3. Browser-local storage

Local storage is simple but does not meet permanent server-side persistence, does not follow the user between devices, and cannot share the existing cleanup semantics when files or users are deleted.

## Architecture

A new Vite entry, `src/epubHighlights.js`, registers `EPUBJS.reader.plugins.Highlights` before `epubviewer-ready.mjs` creates the reader. This avoids modifying the minified third-party reader bundle.

The plugin owns four bounded components:

1. **Selection capture** converts the current DOM range into two point CFIs.
2. **Persistence** writes `highlight` annotations through `session.setBookmark` and deletes them through `session.deleteBookmark`, with optimistic UI and rollback on request failure.
3. **Rendering** resolves saved point CFIs into DOM ranges for the current chapter.
4. **Controls** provide a floating color palette and a Highlights sidebar for navigation, recoloring, and deletion.

The existing `SidebarController` discovers the plugin through the conventional `HighlightsController` property, so the new panel follows the same show/hide contract as Notes and Bookmarks.

## Persisted representation

Each saved record uses the existing bookmark row and JSON content:

```json
{
  "id": "generated UUID",
  "type": "highlight",
  "date": 1792195200000,
  "edited": 1792195200000,
  "anchor": {
    "version": 1,
    "spinePos": 3,
    "start": "epubcfi(...)",
    "end": "epubcfi(...)"
  },
  "body": "bounded selected-text excerpt",
  "color": "yellow"
}
```

The existing schema limits bookmark `value` to 512 characters and `content` to 4,000 characters. The bookmark `value` therefore stores only the start CFI, while `content` stores the complete annotation. Existing restoration code places the decoded content object in `reader.settings.annotations` without a migration.

Only fixed palette keys are accepted. Raw CSS from persisted state is never evaluated.
Generated highlight IDs are limited to 128 ASCII letters, digits, underscores, or hyphens so they remain safe bookmark-route path segments.

## Point-CFI handling

The selected `Range` is normalized to its first and last intersecting text nodes. The implementation generates independent start and end CFIs with `EPUBJS.EpubCFI.generateCfiFromTextNode`.

Restoration parses each point CFI and resolves its exact text node and character offset. This avoids `generateRangeFromCfi`'s end-of-text fallback, which otherwise moves a boundary one character backward when the saved offset equals the text-node length.

A restored range is accepted only when:

- both endpoints resolve in the current document;
- both parsed CFIs belong to the saved spine position;
- the start precedes the end;
- the range is not collapsed;
- the recovered text is non-empty.

Malformed records remain stored but are ignored by the renderer and shown as unavailable in the sidebar rather than breaking the reader.

## Rendering

### Native path

When the iframe supports the CSS Custom Highlight API, the plugin creates one `Highlight` registry entry per palette color and adds all validated ranges for the current chapter. A generated stylesheet defines the corresponding `::highlight(...)` colors.

This path automatically follows pagination, font changes, and reflow without touching the chapter DOM.

### Compatibility path

When the API is unavailable, the plugin creates a pointer-transparent overlay layer inside the chapter document. It translates each range rectangle to document coordinates using the renderer's horizontal page offset and the iframe scroll position. The layer is rebuilt after chapter display, page changes, reflow, and resize.

The fallback also avoids wrapping or splitting text nodes, preserving CFI stability.

## User interface

After a non-collapsed selection settles, a compact toolbar appears adjacent to the selection with five fixed colors: yellow, green, blue, pink, and orange. Choosing a color immediately creates the highlight. Choosing the same exact range again updates its color rather than creating a duplicate. Escape, chapter navigation, or clicking elsewhere dismisses the toolbar.

The sidebar gains a Highlights panel. Entries are ordered by spine position and start CFI and contain:

- a color selector;
- a bounded plain-text excerpt;
- a link to the highlight start;
- a delete button;
- a save-status region for failures.

All publication-derived text is inserted with `textContent`.

## Limits and validation

`ReaderLimits.policy` gains dedicated highlight limits that fit the existing database columns:

- 1,000 saved highlights per publication;
- 128 safe ASCII characters per highlight ID;
- 480 characters per endpoint CFI;
- 1,024 characters retained for a selected-text excerpt;
- 3,900 UTF-8 bytes for the serialized annotation content;
- five enumerated color keys.

The controller ignores records outside these bounds. New highlights are rejected before persistence when the count or anchor bounds would be exceeded.

Persistence failures roll back the local mutation and display a non-modal error. Deletion failures restore the removed record. Recolor failures restore the previous color.

## Compatibility and lifecycle

- No database migration is required.
- Existing `annotation` notes and `bookmark` records are untouched.
- Highlights are rebuilt from saved state whenever a chapter is displayed.
- Native highlight registries and fallback overlays are cleared when leaving a chapter or destroying the reader.
- The selection toolbar never captures keyboard navigation when hidden and exposes accessible labels when shown.
- Public or otherwise non-writable sessions keep rendering restored highlights but disable mutation controls after the first rejected write.

## Verification

Automated coverage will include:

- annotation validation, bounds, and palette enforcement;
- exact point-CFI generation and end-of-text restoration;
- persistence success and rollback for create, recolor, and delete;
- duplicate-range recoloring;
- ordering and plain-text sidebar rendering;
- native CSS Highlight registration and cleanup;
- fallback overlay geometry and cleanup;
- chapter filtering and re-render events;
- template and Vite entry registration;
- existing bookmark and note behavior;
- PHP bookmark JSON round-tripping and deletion.

A production browser acceptance test will select text in a multi-node EPUB passage, create highlights in multiple colors, navigate and reflow, reload the reader, verify restored colors and exact text, recolor one entry, delete another, and confirm the persisted records through the bookmark endpoint.
