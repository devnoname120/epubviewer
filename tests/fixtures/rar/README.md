# RAR test fixtures

- `sample-rar5.rar` is a solid RAR 5 archive generated from `img/book.png` and
  `img/loading.gif` in this repository.
- `sample-rar4.rar` is `testFiles/FolderTest.rar` from
  [`YuJianrong/node-unrar.js`](https://github.com/YuJianrong/node-unrar.js),
  commit `8c615868c9e2ec5ef2e661c5bcaee20ffad3862c`, distributed under the MIT
  license.
- `comic-rar4.rar` is a RAR 4 archive generated with RAR 6.24 from this
  repository's `img/book.png`, stored as `page-01.PNG`.
- `comic-compressed-rar4.rar` is a RAR 4 archive generated with RAR 6.24 from
  a static 1x1 GIF containing a large comment block. It exercises the selected
  entry compression-ratio limit without introducing third-party image data.
- `comic-ppm-rar4.rar` is a RAR 4 archive generated with RAR 6.24 from
  `img/book.png` plus a deterministic PNG text chunk, forced through RAR3 PPMd
  compression and stored as `page-01.PNG`. It covers the bounded libarchive
  fallback used when the primary JavaScript RAR3 decoder cannot decode an
  otherwise valid compressed entry.
