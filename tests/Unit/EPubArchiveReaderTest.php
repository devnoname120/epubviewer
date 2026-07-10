<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Preview\EPubArchiveLimits;
use OCA\Epubviewer\Preview\EPubArchiveReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class EPubArchiveReaderTest extends TestCase {
	/** @var list<string> */
	private array $temporaryFiles = [];

	protected function tearDown(): void {
		foreach ($this->temporaryFiles as $file) {
			@unlink($file);
		}

		parent::tearDown();
	}

	public function testAppMetadataDeclaresArchiveReaderExtensions(): void {
		$infoXml = file_get_contents(dirname(__DIR__, 2) . '/appinfo/info.xml');
		self::assertIsString($infoXml);
		self::assertStringContainsString('<lib>dom</lib>', $infoXml);
		self::assertStringContainsString('<lib>zip</lib>', $infoXml);
	}

	public function testReadsCoverFromValidEpubWithinBounds(): void {
		$cover = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
		self::assertIsString($cover);

		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(),
			'OEBPS/images/cover.png' => $cover,
		]);

		$result = (new EPubArchiveReader($this->limits()))->readCover($file);

		self::assertSame([
			'data' => $cover,
			'mime' => 'image/png',
			'path' => 'OEBPS/images/cover.png',
		], $result);
	}

	public function testReturnsNullWhenPackageHasNoCoverReference(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(false),
		]);

		self::assertNull((new EPubArchiveReader($this->limits()))->readCover($file));
	}

	public function testReadsEpub3CoverImageManifestProperty(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXmlV3(),
			'OEBPS/images/cover.png' => 'epub3-cover',
		]);

		$result = (new EPubArchiveReader($this->limits()))->readCover($file);

		self::assertSame([
			'data' => 'epub3-cover',
			'mime' => 'image/png',
			'path' => 'OEBPS/images/cover.png',
		], $result);
	}

	public function testRejectsArchiveBeforeOpeningWhenInputLimitIsExceeded(): void {
		$file = $this->temporaryFile();
		$handle = fopen($file, 'wb');
		self::assertIsResource($handle);
		self::assertTrue(ftruncate($handle, 1025));
		fclose($handle);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('1024-byte preview input limit');

		(new EPubArchiveReader($this->limits(maxArchiveBytes: 1024)))->readCover($file);
	}

	public function testRejectsTooManyArchiveEntries(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(),
			'OEBPS/images/cover.png' => 'cover',
			'extra.txt' => 'extra',
		]);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('more than 3 archive entries');

		(new EPubArchiveReader($this->limits(maxEntryCount: 3)))->readCover($file);
	}

	public function testIgnoresOversizedUnrelatedEntry(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(),
			'OEBPS/images/cover.png' => 'cover',
			'oversized.bin' => str_repeat('x', 16385),
		]);

		self::assertSame('cover', (new EPubArchiveReader($this->limits()))->readCover($file)['data'] ?? null);
	}

	public function testIgnoresUnrelatedEntriesAboveCumulativeUncompressedLimit(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(),
			'OEBPS/images/cover.png' => 'cover',
			'one.bin' => str_repeat('a', 7000),
			'two.bin' => str_repeat('b', 7000),
		]);

		$result = (new EPubArchiveReader($this->limits(
			maxTotalUncompressedBytes: 12000,
			maxEntryUncompressedBytes: 10000,
		)))->readCover($file);

		self::assertSame('cover', $result['data'] ?? null);
	}

	public function testIgnoresExcessiveCompressionRatioOnUnrelatedEntry(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(),
			'OEBPS/images/cover.png' => 'cover',
			'compressed.bin' => str_repeat('a', 8192),
		]);

		$result = (new EPubArchiveReader($this->limits(
			maxCompressionRatio: 2,
			minCompressionRatioBytes: 4096,
		)))->readCover($file);

		self::assertSame('cover', $result['data'] ?? null);
	}

	public function testRejectsExcessiveCumulativeSizeOfReadEntries(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(),
			'OEBPS/images/cover.png' => str_repeat('c', 10000),
		]);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('cumulative uncompressed preview limit');

		(new EPubArchiveReader($this->limits(
			maxTotalUncompressedBytes: 10000,
			maxEntryUncompressedBytes: 10000,
			maxCoverBytes: 10000,
		)))->readCover($file);
	}

	public function testRejectsExcessiveCompressionRatioOnCover(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(),
			'OEBPS/images/cover.png' => str_repeat('c', 8192),
		]);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('cover image exceeds the allowed compression ratio');

		(new EPubArchiveReader($this->limits(
			maxCompressionRatio: 2,
			minCompressionRatioBytes: 4096,
		)))->readCover($file);
	}

	public function testRejectsOversizedContainerDocument(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(),
		]);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('container document exceeds the 64-byte preview limit');

		(new EPubArchiveReader($this->limits(maxContainerXmlBytes: 64)))->readCover($file);
	}

	public function testRejectsOversizedPackageDocument(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(),
		]);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('package document exceeds the 128-byte preview limit');

		(new EPubArchiveReader($this->limits(maxPackageDocumentBytes: 128)))->readCover($file);
	}

	public function testRejectsOversizedCover(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(),
			'OEBPS/images/cover.png' => str_repeat('c', 65),
		]);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('cover image exceeds the 64-byte preview limit');

		(new EPubArchiveReader($this->limits(maxCoverBytes: 64)))->readCover($file);
	}

	/**
	 * @return array<string, array{bool}>
	 */
	public static function xmlDocumentProvider(): array {
		return [
			'container document' => [true],
			'package document' => [false],
		];
	}

	/**
	 * @dataProvider xmlDocumentProvider
	 */
	public function testRejectsDtdBeforeParsingXml(bool $dtdInContainer): void {
		$container = $this->containerXml();
		$package = $this->packageXml();
		$doctype = '<!DOCTYPE container SYSTEM "http://127.0.0.1/epubviewer-test.dtd">';

		if ($dtdInContainer) {
			$container = str_replace('?>', '?>' . $doctype, $container);
		} else {
			$packageDoctype = str_replace('container', 'package', $doctype);
			$package = str_replace('?>', '?>' . $packageDoctype, $package);
		}

		$file = $this->createEpub([
			'META-INF/container.xml' => $container,
			'OEBPS/content.opf' => $package,
		]);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('prohibited DTD or entity declaration');

		(new EPubArchiveReader($this->limits()))->readCover($file);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function unsafeCoverHrefProvider(): array {
		return [
			'external URL' => ['https://example.invalid/cover.png', 'safe archive-relative path'],
			'archive root escape' => ['../../../cover.png', 'archive path escapes its root'],
		];
	}

	/**
	 * @dataProvider unsafeCoverHrefProvider
	 */
	public function testRejectsUnsafeCoverHref(string $href, string $expectedMessage): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(coverHref: $href),
		]);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage($expectedMessage);

		(new EPubArchiveReader($this->limits()))->readCover($file);
	}

	/**
	 * @param array<string, string> $entries
	 */
	private function createEpub(array $entries): string {
		$file = $this->temporaryFile();
		$zip = new ZipArchive();
		self::assertTrue($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE));

		foreach ($entries as $name => $data) {
			self::assertTrue($zip->addFromString($name, $data));
		}

		self::assertTrue($zip->close());

		return $file;
	}

	private function temporaryFile(): string {
		$file = tempnam(sys_get_temp_dir(), 'epubviewer-test-');
		self::assertIsString($file);
		$this->temporaryFiles[] = $file;

		return $file;
	}

	private function containerXml(): string {
		return '<?xml version="1.0"?>'
			. '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0">'
			. '<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>'
			. '</container>';
	}

	private function packageXml(bool $withCover = true, string $coverHref = 'images/cover.png'): string {
		$coverMetadata = $withCover ? '<meta name="cover" content="cover-image"/>' : '';
		$coverItem = $withCover ? '<item id="cover-image" href="' . htmlspecialchars($coverHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" media-type="image/png"/>' : '';

		return '<?xml version="1.0"?>'
			. '<package xmlns="http://www.idpf.org/2007/opf" version="2.0">'
			. '<metadata>' . $coverMetadata . '</metadata>'
			. '<manifest>' . $coverItem . '</manifest>'
			. '</package>';
	}

	private function packageXmlV3(): string {
		return '<?xml version="1.0"?>'
			. '<package xmlns="http://www.idpf.org/2007/opf" version="3.0">'
			. '<metadata/>'
			. '<manifest><item id="cover-image" href="images/cover.png" media-type="image/png" properties="cover-image"/></manifest>'
			. '</package>';
	}

	private function limits(
		int $maxArchiveBytes = 1024 * 1024,
		int $maxEntryCount = 100,
		int $maxTotalUncompressedBytes = 64 * 1024,
		int $maxEntryUncompressedBytes = 16 * 1024,
		int $maxCompressionRatio = 200,
		int $minCompressionRatioBytes = 16 * 1024,
		int $maxContainerXmlBytes = 4 * 1024,
		int $maxPackageDocumentBytes = 8 * 1024,
		int $maxCoverBytes = 8 * 1024,
	): EPubArchiveLimits {
		return new EPubArchiveLimits(
			$maxArchiveBytes,
			$maxEntryCount,
			$maxTotalUncompressedBytes,
			$maxEntryUncompressedBytes,
			$maxCompressionRatio,
			$minCompressionRatioBytes,
			$maxContainerXmlBytes,
			$maxPackageDocumentBytes,
			$maxCoverBytes,
		);
	}
}
