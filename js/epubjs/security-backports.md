# epub.js 0.2 security backports

This application still uses epub.js 0.2.15 because the reader integration is built around that branch's synchronous APIs. The shipped minified files carry the following focused security changes until the renderer can be migrated.

## Sandboxed chapter documents

`epub.min.js` sets `sandbox="allow-same-origin"` on every chapter iframe before it is attached to the document. Script permission is deliberately omitted, while `allow-same-origin` preserves the parent reader's access to the chapter document for pagination, selection, search, and annotations.

This backports the security boundary introduced for [GHSA-c6rp-xvqv-mwmf](https://github.com/advisories/GHSA-c6rp-xvqv-mwmf) by upstream commit [`ab4dd46408cce0324e1c67de4a3ba96b59e5012e`](https://github.com/futurepress/epub.js/commit/ab4dd46408cce0324e1c67de4a3ba96b59e5012e).

## Text-only metadata and annotations

`reader.min.js` renders EPUB title/creator metadata with jQuery's `text()` method and annotation popup bodies through `textContent`. Neither EPUB-controlled metadata nor persisted annotation bodies are interpreted as HTML.

The regression coverage is in `tests/Unit/ReaderSecurityTest.php`.
