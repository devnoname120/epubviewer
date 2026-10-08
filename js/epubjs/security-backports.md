# epub.js 0.2 security backports

This application still uses epub.js 0.2.15 because the reader integration is built around that branch's synchronous APIs. The shipped minified files carry the following focused security changes until the renderer can be migrated.

## Sandboxed chapter documents

`epub.min.js` sets `sandbox="allow-same-origin"` on every chapter iframe before it is attached to the document. Script permission is deliberately omitted, while `allow-same-origin` preserves the parent reader's access to the chapter document for pagination, selection, search, and annotations.

This backports the security boundary introduced for [GHSA-c6rp-xvqv-mwmf](https://github.com/advisories/GHSA-c6rp-xvqv-mwmf) by upstream commit [`ab4dd46408cce0324e1c67de4a3ba96b59e5012e`](https://github.com/futurepress/epub.js/commit/ab4dd46408cce0324e1c67de4a3ba96b59e5012e).

## Text-only metadata and annotations

`reader.min.js` renders EPUB title/creator metadata with jQuery's `text()` method and annotation popup bodies through `textContent`. Neither EPUB-controlled metadata nor persisted annotation bodies are interpreted as HTML.

The regression coverage is in `tests/Unit/ReaderSecurityTest.php`.

## Publication resource limits

EPUB downloads use a cancellable stream capped at 100 MiB, with a 30-second timeout. ZIP metadata and actual inflation output are checked by the JSZip backport described in `libs/zip-backports.md`. Text resources are limited to 4 MiB before XML/DOM parsing.

The same `js/reader-limits.js` policy is used by comic archive workers and reader controls. Comic ZIP, TAR, GZIP, and RAR extraction checks entry and output budgets; worker deadlines terminate extraction after 30 seconds. RAR headers and dictionary requirements are checked before the decoder starts. Comic image headers reject unknown dimensions, animation, dimensions over 8,192 pixels, and images over 16,777,216 pixels. Decoding keeps at most four nearby pages and 67,108,864 retained pixels. Canvas backing stores are capped at 16,777,216 pixels. Encoded blobs remain bounded by the archive output budget; their URLs are revoked on eviction and all resources are released on close or rejection.

PDFs exceeding 1,000 pages are refused before per-page UI initialization. Search extraction is demand driven, sequential, cancellable, and limited to 131,072 characters per page and 4,194,304 retained characters. Search results and imported annotations are capped at 1,000. PDF labels and search snippets are assembled with text nodes and programmatic spans.

Files exceeding these policies show a reader error. Animated comic images and animated cover previews are deliberately refused; static JPEG, PNG, GIF, and WebP remain supported.
