# EPUB archive fixtures

These small publications exercise `EPubArchiveReader` against real EPUB/ZIP
files. They are intentionally synthetic so that their expected cover bytes,
paths, media types, and rejection behavior stay stable.

| Fixture | Expected behavior |
| --- | --- |
| `epub2-metadata-cover.epub` | Finds the PNG selected by EPUB 2 `<meta name="cover">` metadata. |
| `epub3-cover-image-properties.epub` | Ignores a stale EPUB 2 cover pointer and finds the JPEG when `cover-image` is one token among several manifest properties. |
| `nested-percent-encoded-cover.epub` | Resolves an encoded package path and a relative, percent-encoded cover href. |
| `epub3-large-chapter-small-cover.epub` | Finds a small cover without inflating or rejecting an unrelated XHTML chapter larger than the preview entry limit. |
| `no-cover.epub` | Returns no cover even though the publication contains an ordinary image. |
| `rejected-dtd.epub` | Rejects a container document with an external DTD/entity declaration. |
| `rejected-external-cover.epub` | Rejects an absolute external cover URL. |
| `rejected-escaping-cover.epub` | Rejects a relative cover path that escapes the archive root. |

The positive EPUB 3 fixtures contain navigation documents and use declared
package-vocabulary prefixes where custom manifest properties are useful. A
separate unit test constructs a non-conforming query/fragment cover href to
preserve the reader's existing tolerant lookup without presenting that input as
a conforming example publication. The corpus follows the EPUB 3.3
[OCF `mimetype` rules](https://www.w3.org/TR/epub-33/#sec-zip-container-mime)
and [package manifest rules](https://www.w3.org/TR/epub-33/#sec-pkg-manifest).

`nested-percent-encoded-cover.epub` intentionally has spaces in ZIP entry
names so the fixture can verify percent-decoding in both the container and
manifest paths. EPUBCheck reports its filename-space warnings as expected; the
publication otherwise supplies the required package, navigation, and content
documents.

The positive corpus was checked with EPUBCheck 5.3.0. The EPUB 2, ordinary
EPUB 3, large-chapter, and no-cover fixtures produce no errors or warnings. The
nested encoded fixture produces no errors and only its four expected `PKG-010`
filename-space warnings.

## Regenerating the corpus

Run this command from the repository root:

```console
php tests/fixtures/epub/build.php
```

The builder does not call the system `zip` command. It writes the small ZIP
records directly, stores `mimetype` as the first uncompressed entry, stores all
entries without compression, and fixes every entry timestamp to
`2000-01-01 00:00:00` in the ZIP's DOS timestamp representation. It also fixes
ZIP flags, creator/version fields, entry order, attributes, comments, and extra
fields. Regeneration is therefore byte-for-byte deterministic.

`EPubArchiveFixtureTest` regenerates the complete corpus in a temporary
directory and compares every generated archive with its committed counterpart.
If an intentional fixture change alters the bytes, run the builder and commit
both the source and resulting `.epub` files.
