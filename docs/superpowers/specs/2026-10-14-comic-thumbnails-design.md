# CBR/CBZ Thumbnail Generation Design

**Status:** Accepted

**Date:** 2026-10-14

**Issue:** [devnoname120/epubviewer#47](https://github.com/devnoname120/epubviewer/issues/47)

## Objective

Generate Nextcloud previews from the cover page of CBZ files and, when the PHP RAR extension is installed, CBR files. The implementation must remain deterministic, bounded, portable across the application's supported PHP and Nextcloud versions, and materially narrower than a general-purpose comic extraction service.

## Non-goals

- Implementing RAR decompression in PHP.
- Running `7z`, `unrar`, or another subprocess.
- Extracting an archive to a directory.
- Rendering every page or caching page derivatives.
- Supporting CB7, CBT, CBA, encrypted archives, or multi-volume CBR files.
- Parsing `ComicInfo.xml` to choose an explicitly tagged front cover in this change.
- Making `ext-rar` a hard application dependency.

## Library survey

The available packages fall into three categories.

### Native RAR extraction

[`cataphract/php-rar`](https://github.com/cataphract/php-rar), distributed as PECL `ext-rar`, is the only suitable extraction backend found. It embeds the UnRAR implementation, supports RAR4 and RAR5, exposes archive iteration and `RarEntry::getStream()`, and is maintained for PHP 8.2 through 8.5. Its current issue queue is empty. Earlier PHP-version, build, Unicode-path, and stream issues have been fixed and closed.

[`cataphract/php-libarchive`](https://github.com/cataphract/php-libarchive) is another native extension, but libarchive's issue tracker still contains RAR5 solid-archive, truncation, unsupported-filter, and memory-safety failures. It is a less reliable RAR backend than UnRAR for this use case.

### Metadata-only pure PHP

[`selective-php/rar`](https://github.com/selective-php/rar) is current and well structured, but intentionally parses metadata only. Its maintainer confirms in [issue #2](https://github.com/selective-php/rar/issues/2) that it cannot extract entry contents. Other pure-PHP RAR projects found through targeted repository and code searches likewise stop at metadata parsing.

Searches for RAR decompression internals—including RAR4 unpack routines, RAR5 block decoding, `RarVM`, and Huffman decode paths—found no maintained pure-PHP RAR4/RAR5 decompressor.

### Wrappers and comic packages

- [`wapmorgan/unified-archive`](https://github.com/wapmorgan/UnifiedArchive) delegates RAR extraction to `ext-rar` or external 7-Zip. Its issues expose backend and codec dependence.
- [`kiwilan/php-archive`](https://github.com/kiwilan/php-archive) similarly delegates to `ext-rar` or p7zip. [Issue #58](https://github.com/kiwilan/php-archive/issues/58) documents newer RAR5 and locale-dependent filename failures.
- [`kiwilan/php-ebook`](https://github.com/kiwilan/php-ebook) adds comic-cover selection but inherits those backends. [Issue #127](https://github.com/kiwilan/php-ebook/issues/127) documents missed PNG and uppercase-extension covers, and its implementation performs more extraction work than thumbnail generation needs.
- `cloud-castle/archive` has strong resource-limit and test discipline, but its RAR backend still delegates to `ext-rar` or the proprietary `unrar` executable. Its roughly 59,000 lines of PHP would add a large dependency surface without adding extraction capability.

Existing PHP comic-reader applications mostly call `ZipArchive`, `ext-rar`, browser decoders, or external tools directly. Panel Page Flip provides useful defensive patterns—entry, expansion, path, output, and timeout bounds—but its CBR implementation still invokes 7-Zip.

## Chosen approach

Use the narrow native APIs directly:

- CBZ: PHP `ZipArchive`, already required by the application.
- CBR: optional PECL `ext-rar`.

Do not add a Composer runtime library. Add `ext-rar` under Composer `suggest` so administrators can discover the optional capability.

This approach has the smallest dependency and attack surface, avoids process management, permits streaming one selected entry, and avoids wrapper behavior that can decompress or materialize unrelated entries.

## Provider registration and availability

Register two Nextcloud preview providers:

- `CBZPreview` for `application/x-cbz` and `application/comicbook+zip`.
- `CBRPreview` for `application/x-cbr` and `application/comicbook+rar`.

CBZ is always registered. CBR is registered only when `extension_loaded('rar')` is true. `CBRPreview::isAvailable()` also checks the extension defensively.

The two providers share the image-loading, scaling, logging, temporary-file, and cleanup flow in an abstract comic preview provider. Format-specific archive readers remain separate.

## Data flow

1. The provider determines whether Nextcloud must create a local temporary copy because the source is encrypted or on non-local storage.
2. Already-local archives are opened in place and have no total compressed-file-size ceiling.
3. Remote or encrypted files are copied by `stream_copy_to_stream()` into a temporary file with a 1 GiB plus one byte bound. The extra byte detects a stale or dishonest source size. A copy larger than 1 GiB is rejected and deleted.
4. The format reader scans bounded archive metadata and retains only the best cover candidate. It does not build a complete sorted entry array.
5. The reader validates declared sizes and compression characteristics before opening the selected entry.
6. At most 32 MiB plus one byte is read from the selected entry stream. The actual byte count must agree with the declared unpacked size.
7. Existing header-only image checks validate the encoded format, dimensions, pixel count, and animation state before `OCP\Image` allocates raster memory.
8. The provider loads the validated bytes, scales down to the requested dimensions, and returns the image.
9. Temporary files and streams are closed in `finally` blocks on all paths.

## Cover selection

A cover candidate is an archive entry whose path extension is JPEG, JPG, PNG, GIF, or WebP, case-insensitively. The encoded bytes, not the extension, determine the final image type.

Candidates are compared using case-insensitive natural ordering, then bytewise ordering as a deterministic tie-break. If names remain identical, the earlier archive entry wins. This makes `page-2.jpg` sort before `page-10.jpg` while preserving deterministic behavior for case variants and duplicate names.

The selector rejects or ignores:

- directories;
- empty names and NUL bytes;
- absolute paths, drive-prefixed paths, and `..` traversal components;
- paths longer than 1,024 bytes or deeper than 16 components;
- `__MACOSX` metadata;
- hidden path components;
- unsupported extensions.

The chosen archive entry is addressed by identity, not by name: ZIP uses its numeric entry index and RAR retains the selected `RarEntry` object. Duplicate filenames therefore cannot redirect extraction.

If the first selected image is encrypted, malformed, too large, or otherwise unreadable, preview generation fails closed rather than silently substituting a later page.

## Resource limits

The defaults are constructor-injectable for tests and future configuration.

| Resource | Default | Rationale |
|---|---:|---|
| Remote/encrypted temporary copy | 1 GiB | Bounds network traffic and temporary disk use without rejecting already-local large comics |
| Archive entries | 10,000 | Bounds metadata traversal and native/PHP object work |
| ZIP central directory | 16 MiB | Bounds metadata allocation before libzip opens the archive |
| Entry path | 1,024 bytes / 16 components | Bounds comparison and path-processing work |
| Selected image declared size | 32 MiB | Bounds encoded cover memory |
| Selected image stream read | 32 MiB + 1 byte | Detects inconsistent or dishonest metadata |
| Compression ratio | 200:1 above 1 MiB | Rejects high-expansion selected entries without penalizing small repetitive data |
| Solid-RAR prefix | 1 GiB declared uncompressed | Bounds work UnRAR may need before the selected member of a solid archive |
| Image dimensions | 8,192 pixels per axis | Bounds decoder allocations |
| Decoded image pixels | 16,777,216 | Approximately 64 MiB for one 32-bit raster before decoder overhead |

A local archive's total compressed size is not a useful memory bound because native readers access it by path. Local work is instead bounded by metadata cardinality, the selected entry, and—only for solid RAR—the declared uncompressed prefix through that entry.

## CBZ reader

Before constructing `ZipArchive`, a generalized ZIP metadata preflight reads the EOCD or ZIP64 records directly and verifies:

- a single-disk archive;
- a bounded entry count;
- a bounded, internally consistent central directory;
- non-overflowing metadata offsets;
- complete central-directory records.

The reader then opens the file with `ZipArchive::RDONLY | ZipArchive::CHECKCONS`, scans `statIndex()` metadata, and retains the best candidate index. It reads the candidate with `getStreamIndex()` so duplicate names remain unambiguous.

Encrypted selected entries are rejected. When available, ZIP encryption metadata is checked before opening; stream-open failure remains a fail-closed fallback.

## CBR reader

The reader first verifies that `RarArchive` is available, then opens the archive without a password or volume callback. Header-encrypted archives therefore fail to open, and missing volume requests cannot be satisfied by application code.

It iterates `RarArchive` rather than calling `getEntries()`, avoiding a complete PHP array. During traversal it validates metadata, tracks entry count and the cumulative declared uncompressed prefix, and retains the best `RarEntry` object.

The selected entry must not be a directory, encrypted, split across volumes, oversized, or inconsistent. `RarEntry::getStream()` supplies the payload. Solid archives remain supported, subject to the 1 GiB prefix bound.

The reader does not enable the extension's global exception mode, because changing global RAR error behavior could affect unrelated code in the same PHP process. Native warnings are contained and converted into local preview failure.

## Shared preflight refactoring

The current image-header preflight is coupled to `EPubArchiveLimits`, and the ZIP metadata preflight emits EPUB-specific messages. Refactor these primitives to accept the narrow scalar limits and an archive description they require. EPUB behavior and existing tests remain unchanged apart from generalized wording where necessary.

The refactoring avoids maintaining two security-sensitive parsers with subtly different rules.

## Failure handling and logging

All malformed, unsupported, encrypted, oversized, inconsistent, or unavailable archives return no preview. Expected absence of an image is logged at debug level. Parsing, extraction, and image-validation failures are logged at warning level with the Nextcloud internal path and exception context, but never expose archive contents or passwords.

No archive entry is written to disk. Only Nextcloud's bounded whole-archive temporary copy is created when native libraries require a local path.

## Tests

### Shared selection and image safety

- natural ordering (`page-2` before `page-10`);
- deterministic case and duplicate-name tie-breaking;
- uppercase extensions;
- JPEG, PNG, GIF, and WebP candidates;
- extension/content mismatch accepted only when bytes are a supported image;
- hidden, metadata, absolute, traversal, NUL, overlong, and overdeep paths ignored;
- invalid, oversized, over-dimensioned, over-pixel, and animated images rejected.

### CBZ

- real ZIP entry streaming by index;
- duplicate names do not redirect the selected entry;
- inconsistent ZIP, ZIP64, multi-disk, excessive entry count, and oversized central directory rejection;
- encrypted selected entry rejection;
- selected-entry size and compression-ratio rejection;
- bounded reads and stream cleanup.

### CBR

- provider unavailable and unregistered when `ext-rar` is absent;
- RAR4 and RAR5 extraction with real fixtures;
- solid RAR5 extraction;
- no-image archive behavior;
- encrypted, split/multi-volume, malformed, excessive-entry, oversized-entry, unsafe-ratio, and solid-prefix rejection where fixtures or test doubles can exercise the condition;
- stream cleanup and metadata consistency.

### Provider and integration

- MIME regular expressions and registration;
- 1 GiB remote-copy boundary and stale-size detection;
- local files are not copied or rejected solely for total archive size;
- thumbnail loading, scaling, logging, and temporary-file cleanup;
- existing EPUB preview regression suite.

CI installs `ext-rar` in the unit-test/static-analysis matrix so real CBR paths execute for every supported PHP version. A custom static-analysis stub keeps local Psalm usable when the optional extension is absent.

## Documentation

Update the README and application metadata to state:

- CBZ thumbnails work with the existing ZIP dependency.
- CBR thumbnails require the optional PHP RAR extension.
- unsupported or over-limit archives fall back to Nextcloud's generic file icon.
