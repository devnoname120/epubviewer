# Comic Archive Thumbnails Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate bounded Nextcloud thumbnails from the first naturally ordered image in CBZ archives and, when `ext-rar` is installed, CBR archives.

**Architecture:** Two format-specific readers (`CBZArchiveReader` and `CBRArchiveReader`) share path selection, size validation, and bounded stream reading through `ComicArchiveReader`. Two preview providers share temporary-file, image, logging, and scaling behavior through `ComicPreview`; CBR registration remains conditional on `ext-rar`. Existing ZIP and image preflight code is generalized rather than duplicated.

**Tech Stack:** PHP 8.2–8.5, Nextcloud `IProviderV2`, `ZipArchive`, optional PECL `ext-rar`, PHPUnit, Psalm, GitHub Actions.

---

## File map

### New production files

- `lib/Preview/ComicArchiveLimits.php` — immutable defaults and validation for archive, path, cover, ratio, solid-prefix, and image limits.
- `lib/Preview/ComicArchiveReader.php` — common candidate selection, declared-size validation, bounded stream reading, and image-header validation.
- `lib/Preview/CBZArchiveReader.php` — ZIP metadata traversal and selected-entry streaming by numeric index.
- `lib/Preview/RarMetadataPreflight.php` — minimal RAR4/RAR5 main-header parser that rejects header encryption and multi-volume archives before UnRAR can discover sibling volumes.
- `lib/Preview/CBRArchiveReader.php` — optional `ext-rar` traversal and selected-entry streaming.
- `lib/Preview/ComicPreview.php` — common Nextcloud preview flow and 1 GiB temporary-copy enforcement.
- `lib/Preview/CBZPreview.php` — CBZ MIME provider.
- `lib/Preview/CBRPreview.php` — CBR MIME provider and optional-extension availability check.

### Modified production files

- `lib/Preview/ImageHeaderPreflight.php` — accept scalar image limits and return the validated MIME type.
- `lib/Preview/ZipMetadataPreflight.php` — accept scalar metadata limits and a generic archive label.
- `lib/Preview/EPubArchiveReader.php` — call the generalized preflight APIs without changing EPUB behavior.
- `lib/AppInfo/Application.php` — register CBZ unconditionally and CBR only when `ext-rar` is loaded.
- `composer.json`, `composer.lock` — advertise optional `ext-rar`.
- `README.md`, `appinfo/info.xml` — document comic file thumbnails and the optional CBR dependency.
- `.github/workflows/static-code-analysis.yml` — install `rar` where unit tests and Psalm execute.
- `psalm.xml` — load the local optional-extension stub.

### New and modified tests

- `tests/Unit/ComicArchiveLimitsTest.php`
- `tests/Unit/ComicArchiveReaderTest.php`
- `tests/Unit/CBZArchiveReaderTest.php`
- `tests/Unit/RarMetadataPreflightTest.php`
- `tests/Unit/CBRArchiveReaderTest.php`
- `tests/Unit/ComicPreviewTest.php`
- `tests/Unit/ComicPreviewRegistrationTest.php`
- `tests/stubs/rar.php`
- `tests/fixtures/rar/comic-rar4.rar`
- `tests/fixtures/rar/README.md`
- `tests/Unit/SecurityReportTest.php`

---

### Task 1: Generalize the existing ZIP and image preflights

**Files:**
- Modify: `lib/Preview/ImageHeaderPreflight.php`
- Modify: `lib/Preview/ZipMetadataPreflight.php`
- Modify: `lib/Preview/EPubArchiveReader.php`
- Modify: `tests/Unit/SecurityReportTest.php`

- [ ] **Step 1: Change tests to call generic scalar preflight APIs**

Replace the EPUB-limits arguments in `tests/Unit/SecurityReportTest.php` with explicit scalar limits:

```php
ZipMetadataPreflight::check(
	$this->fixture(),
	maxEntryCount: 1,
	maxCentralDirectoryBytes: EPubArchiveLimits::DEFAULT_MAX_CENTRAL_DIRECTORY_BYTES,
	archiveDescription: 'EPUB',
);

ImageHeaderPreflight::check(
	$data,
	maxBytes: EPubArchiveLimits::DEFAULT_MAX_COVER_BYTES,
	maxDimension: 8192,
	maxPixels: 16 * 1024 * 1024,
);
```

For the pixel-budget test, pass `maxPixels: 1`. Update `checkTemporaryArchive()` to pass the default entry and central-directory limits explicitly.

- [ ] **Step 2: Run the focused tests and verify the signature mismatch**

Run:

```bash
php composer.phar install --dev --no-interaction
vendor/bin/phpunit --configuration tests/phpunit.xml tests/Unit/SecurityReportTest.php
```

Expected: failures reporting that `ZipMetadataPreflight::check()` and `ImageHeaderPreflight::check()` do not accept the new arguments.

- [ ] **Step 3: Generalize `ImageHeaderPreflight` without weakening checks**

Change the signature and return the validated MIME type:

```php
public static function check(
	string $data,
	int $maxBytes,
	int $maxDimension,
	int $maxPixels,
): string {
	if (strlen($data) > $maxBytes) {
		throw new \UnexpectedValueException('The cover exceeds the encoded image limit.');
	}

	$info = @getimagesizefromstring($data);
	if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
		throw new \UnexpectedValueException('The cover has unknown or invalid image dimensions.');
	}

	[$width, $height, $type] = $info;
	if ($width < 1 || $height < 1 || $width > $maxDimension
		|| $height > $maxDimension || $width * $height > $maxPixels) {
		throw new \UnexpectedValueException('The cover exceeds the decoded image pixel limit.');
	}

	if ($type === IMAGETYPE_GIF) {
		self::checkGif($data, $width, $height);
	} elseif ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
		self::checkChunks($data, $type === IMAGETYPE_PNG, $width, $height);
	}

	return image_type_to_mime_type($type);
}
```

Keep `checkGif()` and `checkChunks()` unchanged.

- [ ] **Step 4: Generalize `ZipMetadataPreflight`**

Use this public signature:

```php
public static function check(
	string $file,
	int $maxEntryCount,
	int $maxCentralDirectoryBytes,
	string $archiveDescription,
): void
```

Replace `$limits->maxEntryCount` and `$limits->maxCentralDirectoryBytes` with the scalar arguments. Prefix errors with `$archiveDescription`, for example:

```php
if ($count > $maxEntryCount) {
	throw new \UnexpectedValueException(sprintf(
		'The %s contains more than %d archive entries before ZIP opening.',
		$archiveDescription,
		$maxEntryCount,
	));
}

if ($directoryBytes > $maxCentralDirectoryBytes || $directoryOffset + $directoryBytes !== $endOffset) {
	throw new \UnexpectedValueException(sprintf(
		'The %s central directory is oversized or inconsistent.',
		$archiveDescription,
	));
}
```

Apply the same label to every existing EPUB-specific error while preserving every structural check.

- [ ] **Step 5: Adapt `EPubArchiveReader`**

Use the generalized calls:

```php
ZipMetadataPreflight::check(
	$file,
	$this->limits->maxEntryCount,
	$this->limits->maxCentralDirectoryBytes,
	'EPUB',
);
```

```php
ImageHeaderPreflight::check(
	$data,
	$this->limits->maxCoverBytes,
	$this->limits->maxImageDimension,
	$this->limits->maxImagePixels,
);
```

- [ ] **Step 6: Run EPUB and security regressions**

Run:

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml \
	tests/Unit/SecurityReportTest.php \
	tests/Unit/EPubArchiveReaderTest.php \
	tests/Unit/EPubArchiveReaderEdgeCaseTest.php \
	tests/Unit/EPubArchiveFixtureTest.php
```

Expected: all tests pass.

- [ ] **Step 7: Commit the shared preflight refactor**

```bash
git add lib/Preview/ImageHeaderPreflight.php lib/Preview/ZipMetadataPreflight.php \
	lib/Preview/EPubArchiveReader.php tests/Unit/SecurityReportTest.php
git commit -m "refactor: share archive preview preflights"
```

---

### Task 2: Add comic limits and common archive-reader behavior

**Files:**
- Create: `lib/Preview/ComicArchiveLimits.php`
- Create: `lib/Preview/ComicArchiveReader.php`
- Create: `tests/Unit/ComicArchiveLimitsTest.php`
- Create: `tests/Unit/ComicArchiveReaderTest.php`

- [ ] **Step 1: Write limit-validation tests**

Create `tests/Unit/ComicArchiveLimitsTest.php` with data-driven zero-value rejection and the cross-limit invariant:

```php
<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Preview\ComicArchiveLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComicArchiveLimitsTest extends TestCase {
	/** @return array<string, array{string}> */
	public static function positiveLimitProvider(): array {
		return [
			'maximum temporary archive bytes' => ['maxTemporaryArchiveBytes'],
			'maximum entry count' => ['maxEntryCount'],
			'maximum central directory bytes' => ['maxCentralDirectoryBytes'],
			'maximum entry name bytes' => ['maxEntryNameBytes'],
			'maximum path depth' => ['maxPathDepth'],
			'maximum cover bytes' => ['maxCoverBytes'],
			'maximum compression ratio' => ['maxCompressionRatio'],
			'minimum ratio bytes' => ['minCompressionRatioBytes'],
			'maximum solid prefix bytes' => ['maxSolidPrefixBytes'],
			'maximum image dimension' => ['maxImageDimension'],
			'maximum image pixels' => ['maxImagePixels'],
		];
	}

	#[DataProvider('positiveLimitProvider')]
	public function testRejectsNonPositiveLimits(string $name): void {
		$this->expectException(\InvalidArgumentException::class);
		new ComicArchiveLimits(...[$name => 0]);
	}

	public function testSolidPrefixMustContainCover(): void {
		$this->expectException(\InvalidArgumentException::class);
		new ComicArchiveLimits(maxCoverBytes: 2, maxSolidPrefixBytes: 1);
	}
}
```

- [ ] **Step 2: Write common candidate and bounded-stream tests**

Create a test-only subclass in `tests/Unit/ComicArchiveReaderTest.php` that exposes protected methods. Cover:

```php
self::assertSame('Pages/Page-2.PNG', $reader->candidate('Pages\\Page-2.PNG'));
self::assertTrue($reader->before('page-2.png', 'page-10.png'));
self::assertFalse($reader->before('page-10.png', 'page-2.png'));
self::assertNull($reader->candidate('../cover.png'));
self::assertNull($reader->candidate('/cover.png'));
self::assertNull($reader->candidate('C:/cover.png'));
self::assertNull($reader->candidate('__MACOSX/cover.png'));
self::assertNull($reader->candidate('.hidden/cover.png'));
self::assertNull($reader->candidate('cover.svg'));
foreach (['cover.JPG', 'cover.jpeg', 'cover.PNG', 'cover.GIF', 'cover.WEBP'] as $candidate) {
	self::assertSame($candidate, $reader->candidate($candidate));
}
```

Also test that a memory stream containing `maxCoverBytes + 1` bytes is rejected and closed, while an exact declared-size stream is returned and closed.

- [ ] **Step 3: Run tests and verify the classes are absent**

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml \
	tests/Unit/ComicArchiveLimitsTest.php \
	tests/Unit/ComicArchiveReaderTest.php
```

Expected: failures because `ComicArchiveLimits` and `ComicArchiveReader` do not exist.

- [ ] **Step 4: Implement `ComicArchiveLimits`**

Create the immutable class with these defaults:

```php
public const DEFAULT_MAX_TEMPORARY_ARCHIVE_BYTES = 1024 * 1024 * 1024;
public const DEFAULT_MAX_ENTRY_COUNT = 10000;
public const DEFAULT_MAX_CENTRAL_DIRECTORY_BYTES = 16 * 1024 * 1024;
public const DEFAULT_MAX_ENTRY_NAME_BYTES = 1024;
public const DEFAULT_MAX_PATH_DEPTH = 16;
public const DEFAULT_MAX_COVER_BYTES = 32 * 1024 * 1024;
public const DEFAULT_MAX_COMPRESSION_RATIO = 200;
public const DEFAULT_MIN_COMPRESSION_RATIO_BYTES = 1024 * 1024;
public const DEFAULT_MAX_SOLID_PREFIX_BYTES = 1024 * 1024 * 1024;
public const DEFAULT_MAX_IMAGE_DIMENSION = 8192;
public const DEFAULT_MAX_IMAGE_PIXELS = 16 * 1024 * 1024;
```

The constructor exposes matching `public readonly int` properties, rejects values below one, rejects `maxTemporaryArchiveBytes === PHP_INT_MAX` because callers add one byte, and requires `maxSolidPrefixBytes >= maxCoverBytes`.

- [ ] **Step 5: Implement `ComicArchiveReader`**

Create an abstract class with this public contract:

```php
/** @return null|array{data: string, mime: string, path: string} */
abstract public function readCover(string $file): ?array;

public function getMaxTemporaryArchiveBytes(): int {
	return $this->limits->maxTemporaryArchiveBytes;
}
```

Implement candidate normalization exactly once:

```php
protected function coverCandidatePath(string $name): ?string {
	if ($name === '' || strlen($name) > $this->limits->maxEntryNameBytes || str_contains($name, "\0")) {
		return null;
	}

	$path = str_replace('\\', '/', $name);
	if (str_starts_with($path, '/') || preg_match('/^[a-z]:\//i', $path) === 1) {
		return null;
	}

	$parts = explode('/', $path);
	if (count($parts) > $this->limits->maxPathDepth) {
		return null;
	}

	foreach ($parts as $part) {
		if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.')) {
			return null;
		}
		if (strcasecmp($part, '__MACOSX') === 0) {
			return null;
		}
	}

	$extension = strtolower(pathinfo($parts[array_key_last($parts)], PATHINFO_EXTENSION));
	if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
		return null;
	}

	return implode('/', $parts);
}
```

Add the shared file and ordering helpers used by both backends:

```php
protected function assertArchiveFileAvailable(string $file, string $description): void {
	if (!is_file($file) || !is_readable($file)) {
		throw new \UnexpectedValueException(sprintf('The %s file is unavailable.', $description));
	}
	$size = filesize($file);
	if ($size === false || $size < 1) {
		throw new \UnexpectedValueException(sprintf('The %s file is empty or has an unknown size.', $description));
	}
}

protected function candidateComesBefore(string $candidate, string $current): bool {
	$natural = strnatcasecmp($candidate, $current);
	return $natural < 0 || ($natural === 0 && strcmp($candidate, $current) < 0);
}
```

Validate selected-entry sizes before reading:

```php
protected function assertSelectedEntrySafe(int $unpackedSize, int $packedSize, string $description): void {
	if ($unpackedSize < 1 || $packedSize < 0) {
		throw new \UnexpectedValueException(sprintf('The %s has invalid archive sizes.', $description));
	}
	if ($unpackedSize > $this->limits->maxCoverBytes) {
		throw new \UnexpectedValueException(sprintf('The %s exceeds the encoded cover limit.', $description));
	}
	if ($unpackedSize >= $this->limits->minCompressionRatioBytes
		&& ($packedSize === 0 || $unpackedSize > $packedSize * $this->limits->maxCompressionRatio)) {
		throw new \UnexpectedValueException(sprintf('The %s exceeds the compression-ratio limit.', $description));
	}
}
```

Implement `readSelectedEntry($stream, int $declaredSize, string $description): string` to read and close the stream using `stream_get_contents($stream, maxCoverBytes + 1)`. Reject false reads, over-limit reads, and byte counts that differ from the declared unpacked size. Implement `validatedCoverMime(string $data): string` by calling the generalized `ImageHeaderPreflight::check()` with `maxCoverBytes`, `maxImageDimension`, and `maxImagePixels`.

- [ ] **Step 6: Run focused tests**

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml \
	tests/Unit/ComicArchiveLimitsTest.php \
	tests/Unit/ComicArchiveReaderTest.php
```

Expected: all tests pass.

- [ ] **Step 7: Commit the shared comic archive layer**

```bash
git add lib/Preview/ComicArchiveLimits.php lib/Preview/ComicArchiveReader.php \
	tests/Unit/ComicArchiveLimitsTest.php tests/Unit/ComicArchiveReaderTest.php
git commit -m "feat: add bounded comic archive primitives"
```

---

### Task 3: Implement CBZ cover extraction

**Files:**
- Create: `lib/Preview/CBZArchiveReader.php`
- Create: `tests/Unit/CBZArchiveReaderTest.php`

- [ ] **Step 1: Write CBZ reader tests using generated ZIP fixtures**

Create ZIPs in temporary files with `ZipArchive::CREATE | ZipArchive::OVERWRITE`. Include tests for:

```php
$zip->addFromString('page-10.png', $bookPng);
$zip->addFromString('page-2.PNG', $bookPng);
self::assertSame('page-2.PNG', $reader->readCover($file)['path']);
```

Add cases for:

- no supported image returns `null`;
- `__MACOSX/cover.png`, `.hidden.png`, `../cover.png`, and `cover.svg` are ignored;
- uppercase PNG is accepted;
- an entry named `.jpg` containing PNG bytes reports `image/png` from content;
- the naturally first image is invalid and causes an exception rather than falling through to page two;
- a selected entry over `maxCoverBytes` is rejected;
- a selected entry over the compression-ratio limit is rejected;
- duplicate names are addressed by the selected numeric index;
- an encrypted selected entry is rejected when the local libzip supports `setEncryptionName()`;
- a central directory over the injected limit is rejected before `ZipArchive::open()`.

- [ ] **Step 2: Run the CBZ tests and verify failure**

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml tests/Unit/CBZArchiveReaderTest.php
```

Expected: failure because `CBZArchiveReader` does not exist.

- [ ] **Step 3: Implement `CBZArchiveReader`**

The reader must first call `$this->assertArchiveFileAvailable($file, 'CBZ')`, then:

```php
ZipMetadataPreflight::check(
	$file,
	$this->limits->maxEntryCount,
	$this->limits->maxCentralDirectoryBytes,
	'CBZ',
);

$zip = new ZipArchive();
$result = @$zip->open($file, ZipArchive::RDONLY | ZipArchive::CHECKCONS);
if ($result !== true) {
	throw new \UnexpectedValueException('The CBZ is not a consistent ZIP archive.');
}
```

Scan with `statIndex()` and retain only the best candidate:

```php
$best = null;
for ($index = 0; $index < $zip->numFiles; ++$index) {
	$stat = $zip->statIndex($index);
	if ($stat === false || !isset($stat['name'], $stat['size'], $stat['comp_size'])
		|| !is_string($stat['name']) || !is_int($stat['size']) || !is_int($stat['comp_size'])) {
		throw new \UnexpectedValueException('The CBZ contains unreadable entry metadata.');
	}

	$path = $this->coverCandidatePath($stat['name']);
	if ($path === null) {
		continue;
	}

	if ($best === null || $this->candidateComesBefore($path, $best['path'])) {
		$best = [
			'index' => $index,
			'path' => $path,
			'size' => $stat['size'],
			'packedSize' => $stat['comp_size'],
			'encryptionMethod' => $stat['encryption_method'] ?? ZipArchive::EM_NONE,
		];
	}
}
```

Validate the selected entry, reject non-integer or non-`EM_NONE` encryption metadata, open it with `getStreamIndex($best['index'])`, and use the common bounded reader. Always close `ZipArchive` in `finally`.

- [ ] **Step 4: Run CBZ and EPUB tests**

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml \
	tests/Unit/CBZArchiveReaderTest.php \
	tests/Unit/EPubArchiveReaderTest.php \
	tests/Unit/SecurityReportTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit CBZ extraction**

```bash
git add lib/Preview/CBZArchiveReader.php tests/Unit/CBZArchiveReaderTest.php
git commit -m "feat: extract bounded CBZ cover images"
```

---

### Task 4: Reject encrypted and multi-volume RAR archives before UnRAR opens siblings

**Files:**
- Create: `lib/Preview/RarMetadataPreflight.php`
- Create: `tests/Unit/RarMetadataPreflightTest.php`

- [ ] **Step 1: Write synthetic RAR4 and RAR5 header tests**

Build RAR4 test bytes with the seven-byte signature followed by a 13-byte main header:

```php
private function rar4(int $flags): string {
	return "Rar!\x1a\x07\x00" . pack('vCvv', 0, 0x73, $flags, 13) . str_repeat("\0", 6);
}
```

Build RAR5 headers with a local variable-integer encoder:

```php
private function vint(int $value): string {
	$result = '';
	do {
		$byte = $value & 0x7f;
		$value >>= 7;
		$result .= chr($value === 0 ? $byte : $byte | 0x80);
	} while ($value !== 0);
	return $result;
}

private function rar5(int $headerType, int $archiveFlags = 0): string {
	$body = $this->vint($headerType) . $this->vint(0);
	if ($headerType === 1) {
		$body .= $this->vint($archiveFlags);
	}
	return "Rar!\x1a\x07\x01\x00" . pack('V', 0) . $this->vint(strlen($body)) . $body;
}
```

Assert that single-volume RAR4/RAR5 headers pass, RAR4 `MHD_VOLUME` (`0x0001`) and `MHD_PASSWORD` (`0x0080`) fail, RAR5 `MHFL_VOLUME` (`0x0001`) fails, RAR5 `HEAD_CRYPT` (`4`) fails, and truncated/overlong variable integers fail. Assert that both repository RAR fixtures pass.

- [ ] **Step 2: Run the tests and verify failure**

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml tests/Unit/RarMetadataPreflightTest.php
```

Expected: failure because `RarMetadataPreflight` does not exist.

- [ ] **Step 3: Implement bounded RAR4/RAR5 parsing**

Use exact signatures and reject self-extracting prefixes:

```php
private const RAR4_SIGNATURE = "Rar!\x1a\x07\x00";
private const RAR5_SIGNATURE = "Rar!\x1a\x07\x01\x00";
```

For RAR4, require main-header type `0x73`, a header size of at least 13 within the file, and reject flags `0x0001` and `0x0080`.

For RAR5, skip the CRC32, decode the header-size VInt with at most three bytes, bound the block by `filesize()`, then decode header type and header flags. Consume optional extra-size (`header flags & 0x0001`) and data-size (`header flags & 0x0002`) VInts before the archive flags. Reject type `4`, require type `1`, and reject archive flag `0x0001`.

Use a shared decoder that rejects continuation beyond eight bytes, offsets beyond the declared header boundary, and integer overflow. Read only the first 128 bytes because all fields needed for the main-header decision are bounded variable integers; validate declared header extent against the real file size without allocating it.

- [ ] **Step 4: Run RAR preflight tests**

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml tests/Unit/RarMetadataPreflightTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit the RAR preflight**

```bash
git add lib/Preview/RarMetadataPreflight.php tests/Unit/RarMetadataPreflightTest.php
git commit -m "feat: preflight RAR archive boundaries"
```

---

### Task 5: Implement optional CBR extraction with real RAR4/RAR5 coverage

**Files:**
- Create: `lib/Preview/CBRArchiveReader.php`
- Create: `tests/Unit/CBRArchiveReaderTest.php`
- Create: `tests/stubs/rar.php`
- Create: `tests/fixtures/rar/comic-rar4.rar`
- Modify: `tests/fixtures/rar/README.md`
- Modify: `psalm.xml`

- [ ] **Step 1: Add a Psalm-only RAR stub**

Create `tests/stubs/rar.php`:

```php
<?php

declare(strict_types=1);

/** @implements IteratorAggregate<int, RarEntry> */
final class RarArchive implements IteratorAggregate {
	public static function open(string $filename, ?string $password = null, ?callable $volumeCallback = null): self|false {}
	public function isSolid(): bool {}
	public function isBroken(): bool {}
	public function close(): bool {}
	/** @return Traversable<int, RarEntry> */
	public function getIterator(): Traversable {}
}

final class RarEntry {
	public function getName(): string {}
	public function getUnpackedSize(): int {}
	public function getPackedSize(): int {}
	/** @return resource|false */
	public function getStream(?string $password = null) {}
	public function isDirectory(): bool {}
	public function isEncrypted(): bool {}
	public function getRedirType(): ?int {}
}
```

Add this before `<projectFiles>` in `psalm.xml`:

```xml
<stubs>
    <file name="tests/stubs/rar.php"/>
</stubs>
```

- [ ] **Step 2: Generate a redistributable RAR4 comic fixture from repository-owned content**

Using a locally installed RAR CLI, create a RAR4 archive containing only `img/book.png` under a comic page name:

```bash
fixture_dir="$(mktemp -d)"
cp img/book.png "$fixture_dir/page-01.PNG"
rar -ma4 -m5 -ep1 a tests/fixtures/rar/comic-rar4.rar "$fixture_dir/page-01.PNG"
rm -rf "$fixture_dir"
bsdtar -tvf tests/fixtures/rar/comic-rar4.rar
```

Expected listing: one `page-01.PNG` entry with uncompressed size equal to `img/book.png`. Document that the fixture was generated from repository-owned `img/book.png`; do not commit the RAR executable.

- [ ] **Step 3: Write CBR integration tests**

Guard extraction tests with:

```php
if (!extension_loaded('rar')) {
	self::markTestSkipped('The optional rar extension is not installed.');
}
```

Assert:

```php
$rar4 = $reader->readCover($this->fixture('comic-rar4.rar'));
self::assertSame('page-01.PNG', $rar4['path']);
self::assertSame(file_get_contents($this->repoRoot() . '/img/book.png'), $rar4['data']);

$rar5 = $reader->readCover($this->fixture('sample-rar5.rar'));
self::assertSame('img/book.png', $rar5['path']);
self::assertSame('image/png', $rar5['mime']);
```

Also assert that `sample-rar4.rar` returns `null`, proving a valid no-image RAR4 is handled. Add injected-limit tests for entry count, selected size, compression ratio, and solid-prefix rejection. Test that calling `readCover()` without the extension throws a local `UnexpectedValueException` rather than a class-not-found error.

- [ ] **Step 4: Run tests without the extension**

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml tests/Unit/CBRArchiveReaderTest.php
```

Expected: the no-extension behavior passes and real extraction tests are skipped.

- [ ] **Step 5: Implement `CBRArchiveReader`**

Start with capability and metadata preflight:

```php
if (!class_exists(\RarArchive::class, false)) {
	throw new \UnexpectedValueException('CBR previews require the PHP RAR extension.');
}

$this->assertArchiveFileAvailable($file, 'CBR');
RarMetadataPreflight::check($file);
$archive = @\RarArchive::open($file);
if (!$archive instanceof \RarArchive || $archive->isBroken()) {
	throw new \UnexpectedValueException('The CBR is not a consistent RAR archive.');
}
```

Iterate without `getEntries()`:

```php
$isSolid = $archive->isSolid();
$count = 0;
$prefixBytes = 0;
$best = null;

foreach ($archive as $entry) {
	if (++$count > $this->limits->maxEntryCount) {
		throw new \UnexpectedValueException('The CBR exceeds the archive entry-count limit.');
	}
	if (!$entry instanceof \RarEntry) {
		throw new \UnexpectedValueException('The CBR contains unreadable entry metadata.');
	}

	$name = $entry->getName();
	$unpackedSize = $entry->getUnpackedSize();
	$packedSize = $entry->getPackedSize();
	if ($unpackedSize < 0 || $packedSize < 0) {
		throw new \UnexpectedValueException('The CBR contains invalid entry sizes.');
	}

	if ($isSolid) {
		$prefixBytes = $unpackedSize > $this->limits->maxSolidPrefixBytes - min($prefixBytes, $this->limits->maxSolidPrefixBytes)
			? $this->limits->maxSolidPrefixBytes + 1
			: $prefixBytes + $unpackedSize;
	}

	if ($entry->isDirectory()) {
		continue;
	}
	$path = $this->coverCandidatePath($name);
	if ($path !== null && ($best === null || $this->candidateComesBefore($path, $best['path']))) {
		$best = [
			'entry' => $entry,
			'path' => $path,
			'unpackedSize' => $unpackedSize,
			'packedSize' => $packedSize,
			'prefixBytes' => $prefixBytes,
		];
	}
}
```

Reject an encrypted or redirected selected entry, enforce the solid-prefix and selected-entry limits, open `getStream()` with no password, and pass it to the common bounded reader. Close `RarArchive` in `finally` without enabling global `RarException` mode.

- [ ] **Step 6: Build `ext-rar` locally and run real extraction tests**

If the extension is absent, build the surveyed source in an isolated temporary directory:

```bash
rar_src=/tmp/epubviewer-archive-survey.7b0yFH/php-rar
build_dir="$(mktemp -d)"
cp -R "$rar_src/." "$build_dir/"
cd "$build_dir"
phpize
./configure
make -j"$(sysctl -n hw.ncpu 2>/dev/null || getconf _NPROCESSORS_ONLN)"
php -d "extension=$build_dir/modules/rar.so" \
	/Volumes/data/.codex/worktrees/4c0f/epubviewer/vendor/bin/phpunit \
	--configuration /Volumes/data/.codex/worktrees/4c0f/epubviewer/tests/phpunit.xml \
	/Volumes/data/.codex/worktrees/4c0f/epubviewer/tests/Unit/CBRArchiveReaderTest.php
```

Expected: RAR4, RAR5, and solid-RAR tests pass with no skips.

- [ ] **Step 7: Run Psalm against the optional-extension stub**

```bash
composer run psalm -- --no-cache --no-progress
```

Expected: no undefined `RarArchive` or `RarEntry` findings.

- [ ] **Step 8: Commit CBR extraction**

```bash
git add lib/Preview/CBRArchiveReader.php tests/Unit/CBRArchiveReaderTest.php \
	tests/stubs/rar.php tests/fixtures/rar/comic-rar4.rar tests/fixtures/rar/README.md psalm.xml
git commit -m "feat: extract optional CBR cover images"
```

---

### Task 6: Add Nextcloud preview providers and conditional registration

**Files:**
- Create: `lib/Preview/ComicPreview.php`
- Create: `lib/Preview/CBZPreview.php`
- Create: `lib/Preview/CBRPreview.php`
- Modify: `lib/AppInfo/Application.php`
- Create: `tests/Unit/ComicPreviewTest.php`
- Create: `tests/Unit/ComicPreviewRegistrationTest.php`

- [ ] **Step 1: Write provider behavior tests with a fake archive reader**

Use a test subclass of `ComicPreview` that injects a fake `ComicArchiveReader`, returns a controlled temporary path, and returns a mocked `IImage` from an overridable `createImage()` method. Cover:

- local files return the storage path without invoking `fopen()` even when `getSize()` exceeds 1 GiB;
- encrypted/non-local files are streamed to the temporary file;
- a remote source of exactly the configured copy limit is accepted using an injected four-byte limit in tests;
- a remote source of limit plus one byte is rejected before the reader is called;
- stale underreported `getSize()` is caught after the bounded copy;
- no-cover returns `null` and logs at debug level;
- parser/image failures return `null`, log at warning level, and delete temporary files;
- valid cover bytes call `loadFromData()` and `scaleDownToFit($maxX, $maxY)`.

Use tiny injected limits such as four bytes so tests do not allocate GiB-sized buffers.

- [ ] **Step 2: Write MIME and registration tests**

Assert:

```php
self::assertSame(
	'/^(?:application\/x-cbz|application\/comicbook\+zip)$/',
	(new CBZPreview($logger))->getMimeType(),
);
self::assertSame(extension_loaded('rar'), (new CBRPreview($logger))->isAvailable($fileInfo));
```

Read `lib/AppInfo/Application.php` and assert that CBZ registration is unconditional while CBR registration appears inside `if (extension_loaded('rar'))`.

- [ ] **Step 3: Run provider tests and verify failure**

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml \
	tests/Unit/ComicPreviewTest.php \
	tests/Unit/ComicPreviewRegistrationTest.php
```

Expected: failures because the provider classes do not exist.

- [ ] **Step 4: Implement the shared `ComicPreview` flow**

Inject the reader into the protected base constructor so Nextcloud never attempts to autowire the abstract reader type:

```php
abstract class ComicPreview extends ProviderV2 {
	protected function __construct(
		private LoggerInterface $logger,
		private ComicArchiveReader $archiveReader,
	) {
		parent::__construct();
	}
```

In `getThumbnail()`:

```php
$temporaryCopy = $this->useTempFile($file);
$copyLimit = $this->archiveReader->getMaxTemporaryArchiveBytes();
if ($temporaryCopy && $file->getSize() > $copyLimit) {
	throw new \UnexpectedValueException('The comic exceeds the temporary preview copy limit.');
}

$localFile = $this->getLocalFile($file, $temporaryCopy ? $copyLimit + 1 : null);
if ($localFile === false) {
	throw new \UnexpectedValueException('The comic could not be copied for preview generation.');
}
if ($temporaryCopy) {
	$copiedSize = filesize($localFile);
	if ($copiedSize === false || $copiedSize > $copyLimit) {
		throw new \UnexpectedValueException('The comic exceeds the temporary preview copy limit.');
	}
}

$cover = $this->archiveReader->readCover($localFile);
if ($cover === null) {
	$this->logger->debug('Comic archive has no previewable cover image.', ['app' => 'epubviewer']);
	return null;
}

$image = $this->createImage();
if (!$image->loadFromData($cover['data']) || !$image->valid()) {
	throw new \UnexpectedValueException('The validated comic cover could not be decoded.');
}
$image->scaleDownToFit($maxX, $maxY);
return $image;
```

Wrap the flow in `try/catch/finally`, logging warnings with the file's internal path and exception while always calling `cleanTmpFiles()`.

- [ ] **Step 5: Implement format providers**

`CBZPreview` has a public `__construct(LoggerInterface $logger)` that calls `parent::__construct($logger, new CBZArchiveReader())` and returns the ZIP MIME regex.

`CBRPreview` has the corresponding constructor with `new CBRArchiveReader()`, returns the RAR MIME regex, and implements:

```php
public function isAvailable(FileInfo $file): bool {
	return extension_loaded('rar');
}
```

- [ ] **Step 6: Register providers in `Application`**

Add:

```php
$context->registerPreviewProvider(
	\OCA\Epubviewer\Preview\CBZPreview::class,
	'/^(?:application\/x-cbz|application\/comicbook\+zip)$/',
);
if (extension_loaded('rar')) {
	$context->registerPreviewProvider(
		\OCA\Epubviewer\Preview\CBRPreview::class,
		'/^(?:application\/x-cbr|application\/comicbook\+rar)$/',
	);
}
```

Keep EPUB registration unchanged.

- [ ] **Step 7: Run provider and compatibility tests**

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml \
	tests/Unit/ComicPreviewTest.php \
	tests/Unit/ComicPreviewRegistrationTest.php \
	tests/Unit/PreviewStreamCopyTest.php \
	tests/Unit/Nextcloud34CompatibilityTest.php \
	tests/Unit/Nextcloud35CompatibilityTest.php
```

Expected: all tests pass.

- [ ] **Step 8: Commit provider integration**

```bash
git add lib/Preview/ComicPreview.php lib/Preview/CBZPreview.php lib/Preview/CBRPreview.php \
	lib/AppInfo/Application.php tests/Unit/ComicPreviewTest.php \
	tests/Unit/ComicPreviewRegistrationTest.php
git commit -m "feat: register comic archive thumbnails"
```

---

### Task 7: Advertise the optional extension and exercise it in CI

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `.github/workflows/static-code-analysis.yml`
- Modify: `README.md`
- Modify: `appinfo/info.xml`

- [ ] **Step 1: Add the Composer suggestion and refresh the lock hash**

Add:

```json
"suggest": {
  "ext-rar": "Enables CBR thumbnail generation"
}
```

Run:

```bash
php composer.phar update --lock --no-install --no-interaction
```

Expected: only Composer metadata/content hash changes; no runtime package is added.

- [ ] **Step 2: Install `rar` in unit/static-analysis CI jobs**

Append `rar` to the `extensions:` value in both `setup-php` steps in `.github/workflows/static-code-analysis.yml`:

```yaml
extensions: ctype,curl,dom,fileinfo,ftp,gd,intl,json,ldap,mbstring,openssl,pdo_sqlite,posix,rar,sqlite,xml,zip
```

- [ ] **Step 3: Document thumbnail behavior**

Add to the README's introductory comic section:

```markdown
Nextcloud file thumbnails use the first naturally ordered supported image in CBZ archives. CBR thumbnails are enabled when the optional PHP `rar` extension is installed. Unsupported, encrypted, multi-volume, malformed, or over-limit archives retain Nextcloud's generic file icon.
```

Add equivalent wording to `appinfo/info.xml` inside the CDATA description. Do not add `<lib>rar</lib>` to dependencies because CBR thumbnails are optional and comic reading itself remains browser-side.

- [ ] **Step 4: Run metadata and compatibility tests**

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml \
	tests/Unit/Nextcloud34CompatibilityTest.php \
	tests/Unit/Nextcloud35CompatibilityTest.php
php composer.phar validate --strict
```

Expected: all tests pass and Composer validation succeeds.

- [ ] **Step 5: Commit dependency metadata, CI, and documentation**

```bash
git add composer.json composer.lock .github/workflows/static-code-analysis.yml README.md appinfo/info.xml
git commit -m "docs: describe optional CBR thumbnails"
```

---

### Task 8: Full verification, review, and publication

**Files:**
- Review all files changed on the branch.

- [ ] **Step 1: Run PHP formatting checks**

```bash
php composer.phar run cs:check
```

Expected: no coding-standard violations. Apply `php composer.phar run cs:fix` only to files changed by this branch, then rerun the check if needed.

- [ ] **Step 2: Run the complete PHPUnit suite without `ext-rar`**

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml
```

Expected: all tests pass; real CBR extraction tests skip, while absence and registration behavior pass.

- [ ] **Step 3: Run the complete suite with the locally built extension**

```bash
php -d "extension=$build_dir/modules/rar.so" vendor/bin/phpunit --configuration tests/phpunit.xml
```

Expected: all tests pass with no CBR extraction skips.

- [ ] **Step 4: Run static and security analysis**

```bash
composer run psalm:ci -- --no-cache --monochrome --no-progress
composer run psalm -- --no-cache --monochrome --no-progress --taint-analysis
```

Expected: no new findings and no obsolete baseline entries.

- [ ] **Step 5: Run syntax, Composer, and repository checks**

```bash
find lib tests -name '*.php' -print0 | xargs -0 -n1 php -l
php composer.phar validate --strict
git diff --check origin/master...HEAD
git status --short
```

Expected: all PHP files parse, Composer validates, no whitespace errors exist, and the working tree is clean except for intentional final fixes.

- [ ] **Step 6: Review the branch diff against the accepted design**

```bash
git diff --stat origin/master...HEAD
git diff origin/master...HEAD -- lib/Preview lib/AppInfo/Application.php tests composer.json \
	.github/workflows/static-code-analysis.yml README.md appinfo/info.xml
```

Verify explicitly that:

- no subprocess execution was added;
- no archive entry is written to disk;
- local archives have no total compressed-size limit;
- only temporary copies have the 1 GiB limit;
- CBR is not registered without `ext-rar`;
- RAR volume and header-encryption flags are rejected before `RarArchive::open()`;
- selected entries are addressed by ZIP index or retained RAR object;
- all streams, archives, and temporary files close on failure paths.

- [ ] **Step 7: Commit any verification fixes**

```bash
git add -A
git commit -m "test: harden comic thumbnail boundaries"
```

Skip this commit only if verification required no changes.

- [ ] **Step 8: Push the feature branch**

```bash
git push -u origin feature/comic-thumbnails
```

Expected: the branch is available at `origin/feature/comic-thumbnails`.

- [ ] **Step 9: Post the implementation result on issue #47**

Use `gh issue comment` with a concise summary of:

- CBZ thumbnails through `ZipArchive`;
- optional CBR thumbnails through `ext-rar`;
- first naturally ordered JPEG/PNG/GIF/WebP image;
- bounded remote copies, metadata, selected-entry, image, and solid-RAR work;
- multi-volume/header-encrypted RAR rejection;
- test coverage and branch/commit link.

Run:

```bash
gh issue comment 47 --repo devnoname120/epubviewer --body-file /tmp/epubviewer-issue-47-response.md
```

- [ ] **Step 10: Record completion**

Run `git status --short --branch`, verify the branch tracks origin and is clean, then mark the Codexify goal completed with the pushed commit and issue-comment URL.
