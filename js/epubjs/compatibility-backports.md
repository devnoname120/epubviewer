# epub.js 0.2 compatibility backports

This application still uses epub.js 0.2.15 because the reader integration is built around that branch's synchronous APIs. The shipped minified bundle carries the following focused compatibility change until the renderer can be migrated.

## XHTML content documents with `.html` filenames

Contained EPUB documents are XML publications even when an XHTML spine item's filename ends in `.html`. Upstream epub.js 0.2.15 derived the `DOMParser` media type from that extension, selected `text/html`, and could lose the document body when valid XML empty-element syntax such as `<script src="js/kobo.js"/>` appeared before it.

`EPUBJS.Unarchiver.prototype.getXml()` now parses contained EPUB XML resources as `text/xml`, matching the existing `EPUBJS.Storage.prototype.getXml()` behavior. This preserves XML namespaces and empty-element semantics for container documents, package documents, navigation documents, XHTML, and SVG without changing generic resource MIME detection.

The iframe sandbox remains `allow-same-origin` without script permission. This compatibility change affects parsing only and does not allow publication scripts to execute.

The regression coverage is in `tests/js/epubjs-xhtml.test.js` and exercises the actual bundled JSZip, unarchiver, and chapter loader with a `.html` XHTML chapter containing a self-closing script followed by visible body content.
