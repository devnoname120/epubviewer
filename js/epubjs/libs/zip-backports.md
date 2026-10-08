# JSZip 2.5 security backports

This application still uses JSZip 2.5.0 because epub.js 0.2 depends on its synchronous constructor, `load`, `file`, and `generate` APIs. Replacing it directly with JSZip 3 would break that integration. The bundled `zip.min.js` carries focused security backports and resource limits without changing the synchronous API.

## Object prototype filenames

For [GHSA-jg8v-48h5-wgxg](https://github.com/advisories/GHSA-jg8v-48h5-wgxg), the file map is created with `Object.create(null)` so names such as `__proto__`, `constructor`, and `toString` are ordinary archive keys. The two loops over that map no longer call an inherited `hasOwnProperty` method. This is the same logic as JSZip's 2.x backport in upstream commit [`ede758d4f7af13204b230911a7c771da7dd59be3`](https://github.com/Stuk/jszip/commit/ede758d4f7af13204b230911a7c771da7dd59be3).

## Relative archive entry names

For [GHSA-36fh-84j7-cv5h](https://github.com/advisories/GHSA-36fh-84j7-cv5h), entry names are normalized while a ZIP is loaded: empty and `.` components are removed, `..` removes the preceding component without traversing above the archive root, and all other components are retained. The loaded entry exposes its original name as `unsafeOriginalName`. This adapts the exact resolver and load-time behavior introduced by upstream commit [`2edab366119c9ee948357c02f1206c28566cdf15`](https://github.com/Stuk/jszip/commit/2edab366119c9ee948357c02f1206c28566cdf15) to JSZip 2.5's synchronous `load` method.

The executable regression coverage is in `tests/js/jszip-security.test.js` and runs through `npm test`.

## Archive resource limits

`js/reader-limits.js` must load before the bundle. `load` validates the end record, central-directory cardinality and size, local entry sizes, cumulative output, and compression ratios before JSZip parses entries. The Pako inflater checks actual output bytes, compression ratio, and elapsed time on each output chunk, including archives with misleading size metadata.

The legacy synchronous extraction API remains in use. Interactive ZIP64, split, and encrypted archives are rejected. Limits are 100 MiB compressed input, 10,000 entries, 16 MiB central-directory metadata, 64 MiB per entry, 512 MiB cumulative declared output, and a 200:1 compression ratio for entries of at least 1 MiB. The server preview separately accepts bounded ZIP64 metadata.
