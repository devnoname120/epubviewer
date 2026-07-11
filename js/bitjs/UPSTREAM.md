# BitJS

The files in `v1.2.6/archive/`, `v1.2.6/file/`, and `v1.2.6/io/` are vendored from
[`@codedread/bitjs` v1.2.6](https://github.com/codedread/bitjs/releases/tag/v1.2.6),
commit `d6e4f65b775ff26f7699b6412ef2134aa2cda535`.

Local compatibility changes:

- `v1.2.6/archive/unzip.js` decodes ZIP entry names marked with the UTF-8 general-purpose flag before
  exposing them to the comic reader.
- The BitJS RAR backend and its `unrar.js`/`rarvm.js` files are omitted. The comic reader routes RAR
  archives exclusively through `@mary/rar` and uses BitJS only for the remaining archive formats.

The versioned directory keeps module-worker URLs distinct from older BitJS files that browsers may
still have cached. The browser integration lives in `../cbrjs/bitjs.js`.
