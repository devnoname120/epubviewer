<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Preview\EPubArchiveReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class EPubArchiveReaderEdgeCaseTest extends TestCase {
	/** @var list<string> */
	private array $temporaryFiles = [];

	protected function tearDown(): void {
		foreach ($this->temporaryFiles as $file) {
			@chmod($file, 0600);
			@unlink($file);
		}

		parent::tearDown();
	}

	public function testRejectsMissingFile(): void {
		$file = sys_get_temp_dir() . '/missing-epubviewer-' . bin2hex(random_bytes(8)) . '.epub';

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('EPUB file is unavailable');

		(new EPubArchiveReader())->readCover($file);
	}

	public function testRejectsUnreadableFile(): void {
		$file = $this->temporaryFile();
		self::assertNotFalse(file_put_contents($file, 'unreadable'));
		self::assertTrue(chmod($file, 0000));
		clearstatcache(true, $file);

		if (is_readable($file)) {
			self::markTestSkipped('The current filesystem user can still read mode-000 files.');
		}

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('EPUB file is unavailable');

		(new EPubArchiveReader())->readCover($file);
	}

	public function testRejectsEmptyFile(): void {
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('EPUB file is empty');

		(new EPubArchiveReader())->readCover($this->temporaryFile());
	}

	public function testRejectsNonZipFile(): void {
		$file = $this->temporaryFile();
		self::assertNotFalse(file_put_contents($file, 'not a ZIP archive'));

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('not a consistent ZIP archive');

		(new EPubArchiveReader())->readCover($file);
	}

	public function testRejectsArchiveWithoutContainerDocument(): void {
		$file = $this->createEpub([
			'OEBPS/content.opf' => $this->packageXml(),
		]);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('does not contain META-INF/container.xml');

		(new EPubArchiveReader())->readCover($file);
	}

	public function testRejectsMissingDeclaredPackageDocument(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml('OEBPS/missing.opf'),
		]);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('package document declared by the EPUB is missing');

		(new EPubArchiveReader())->readCover($file);
	}

	public function testIgnoresRootfileFromForeignNamespace(): void {
		$container = '<?xml version="1.0"?>'
			. '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:foreign="urn:example:foreign" version="1.0">'
			. '<rootfiles>'
			. '<foreign:rootfile full-path="FOREIGN/missing.opf" media-type="application/oebps-package+xml"/>'
			. '<rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>'
			. '</rootfiles>'
			. '</container>';
		$file = $this->createEpub([
			'META-INF/container.xml' => $container,
			'OEBPS/content.opf' => $this->packageXml(),
			'OEBPS/images/cover.png' => 'real-cover',
		]);

		self::assertSame('real-cover', (new EPubArchiveReader())->readCover($file)['data'] ?? null);
	}

	public function testIgnoresCoverMetadataFromForeignNamespace(): void {
		$package = '<?xml version="1.0"?>'
			. '<package xmlns="http://www.idpf.org/2007/opf" xmlns:foreign="urn:example:foreign" version="2.0">'
			. '<metadata>'
			. '<foreign:meta name="cover" content="foreign-cover"/>'
			. '<meta name="cover" content="real-cover"/>'
			. '</metadata>'
			. '<manifest><item id="real-cover" href="images/cover.png" media-type="image/png"/></manifest>'
			. '</package>';
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $package,
			'OEBPS/images/cover.png' => 'real-cover',
		]);

		self::assertSame('real-cover', (new EPubArchiveReader())->readCover($file)['data'] ?? null);
	}

	public function testIgnoresManifestItemFromForeignNamespace(): void {
		$package = '<?xml version="1.0"?>'
			. '<package xmlns="http://www.idpf.org/2007/opf" xmlns:foreign="urn:example:foreign" version="2.0">'
			. '<metadata><meta name="cover" content="cover-image"/></metadata>'
			. '<manifest>'
			. '<foreign:item id="cover-image" href="images/foreign.png" media-type="image/png"/>'
			. '<item id="cover-image" href="images/real.png" media-type="image/png"/>'
			. '</manifest>'
			. '</package>';
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $package,
			'OEBPS/images/foreign.png' => 'foreign-cover',
			'OEBPS/images/real.png' => 'real-cover',
		]);

		self::assertSame('real-cover', (new EPubArchiveReader())->readCover($file)['data'] ?? null);
	}

	public function testAcceptsPrefixedOcfAndOpfElements(): void {
		$container = '<?xml version="1.0"?>'
			. '<ocf:container xmlns:ocf="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0">'
			. '<ocf:rootfiles><ocf:rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></ocf:rootfiles>'
			. '</ocf:container>';
		$package = '<?xml version="1.0"?>'
			. '<opf:package xmlns:opf="http://www.idpf.org/2007/opf" version="2.0">'
			. '<opf:metadata><opf:meta name="cover" content="cover-image"/></opf:metadata>'
			. '<opf:manifest><opf:item id="cover-image" href="images/cover.png" media-type="image/png"/></opf:manifest>'
			. '</opf:package>';
		$file = $this->createEpub([
			'META-INF/container.xml' => $container,
			'OEBPS/content.opf' => $package,
			'OEBPS/images/cover.png' => 'prefixed-namespace-cover',
		]);

		self::assertSame('prefixed-namespace-cover', (new EPubArchiveReader())->readCover($file)['data'] ?? null);
	}

	public function testUsesFirstPackageWhenContainerDeclaresMultipleRootfiles(): void {
		$container = '<?xml version="1.0"?>'
			. '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0">'
			. '<rootfiles>'
			. '<rootfile full-path="FIRST/content.opf" media-type="application/oebps-package+xml"/>'
			. '<rootfile full-path="SECOND/content.opf" media-type="application/oebps-package+xml"/>'
			. '</rootfiles>'
			. '</container>';
		$file = $this->createEpub([
			'META-INF/container.xml' => $container,
			'FIRST/content.opf' => $this->packageXml('images/cover.png'),
			'FIRST/images/cover.png' => 'first-rootfile-cover',
			'SECOND/content.opf' => $this->packageXml('images/cover.png'),
			'SECOND/images/cover.png' => 'second-rootfile-cover',
		]);

		self::assertSame('first-rootfile-cover', (new EPubArchiveReader())->readCover($file)['data'] ?? null);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function malformedXmlProvider(): array {
		return [
			'container document' => ['META-INF/container.xml', '<container>'],
			'package document' => ['OEBPS/content.opf', '<package>'],
		];
	}

	/**
	 * @dataProvider malformedXmlProvider
	 */
	public function testRejectsMalformedXml(string $malformedEntry, string $malformedXml): void {
		$entries = [
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(),
		];
		$entries[$malformedEntry] = $malformedXml;
		$file = $this->createEpub($entries);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('is not valid XML');

		(new EPubArchiveReader())->readCover($file);
	}

	public function testNormalizesParentSegmentThatRemainsInsideArchive(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml('OPS/package/content.opf'),
			'OPS/package/content.opf' => $this->packageXml('../images/cover.jpg', 'image/jpeg'),
			'OPS/images/cover.jpg' => 'nested-cover',
		]);

		self::assertSame([
			'data' => 'nested-cover',
			'mime' => 'image/jpeg',
			'path' => 'OPS/images/cover.jpg',
		], (new EPubArchiveReader())->readCover($file));
	}

	public function testDecodesCoverNameAndIgnoresQueryAndFragment(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml('images/Front%20Cover.jpg?download=1#preview', 'image/jpeg'),
			'OEBPS/images/Front Cover.jpg' => 'encoded-cover',
		]);

		self::assertSame([
			'data' => 'encoded-cover',
			'mime' => 'image/jpeg',
			'path' => 'OEBPS/images/Front Cover.jpg',
		], (new EPubArchiveReader())->readCover($file));
	}

	public function testDecodesEncodedPathSeparatorsUsedByExistingEpubTooling(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml('OPS%2Fpackage%2Fcontent.opf'),
			'OPS/package/content.opf' => $this->packageXml('images%2Fcover.png'),
			'OPS/package/images/cover.png' => 'encoded-separator-cover',
		]);

		self::assertSame([
			'data' => 'encoded-separator-cover',
			'mime' => 'image/png',
			'path' => 'OPS/package/images/cover.png',
		], (new EPubArchiveReader())->readCover($file));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function encodedCoverNameProvider(): array {
		return [
			'number sign' => ['images/cover%23front.png', 'images/cover#front.png'],
			'question mark' => ['images/cover%3Fedition.png', 'images/cover?edition.png'],
			'percent sign' => ['images/100%25.png', 'images/100%.png'],
			'UTF-8' => ['images/Caf%C3%A9.png', 'images/Café.png'],
			'literal plus' => ['images/Cover+Plus.png', 'images/Cover+Plus.png'],
		];
	}

	/**
	 * @dataProvider encodedCoverNameProvider
	 */
	public function testDecodesPercentEncodedCoverName(string $href, string $entryName): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml($href),
			'OEBPS/' . $entryName => 'encoded-name-cover',
		]);

		self::assertSame('OEBPS/' . $entryName, (new EPubArchiveReader())->readCover($file)['path'] ?? null);
	}

	public function testEpub3CoverImagePropertyTakesPrecedenceOverLegacyMetadata(): void {
		$package = '<?xml version="1.0"?>'
			. '<package xmlns="http://www.idpf.org/2007/opf" version="3.0">'
			. '<metadata><meta name="cover" content="epub2-cover"/></metadata>'
			. '<manifest>'
			. '<item id="epub3-cover" href="images/epub3.png" media-type="image/png" properties="cover-image"/>'
			. '<item id="epub2-cover" href="images/epub2.jpg" media-type="image/jpeg"/>'
			. '</manifest>'
			. '</package>';
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $package,
			'OEBPS/images/epub3.png' => 'epub3-cover',
			'OEBPS/images/epub2.jpg' => 'epub2-cover',
		]);

		self::assertSame([
			'data' => 'epub3-cover',
			'mime' => 'image/png',
			'path' => 'OEBPS/images/epub3.png',
		], (new EPubArchiveReader())->readCover($file));
	}

	public function testReturnsNullWhenReferencedCoverEntryIsMissing(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(),
		]);

		self::assertNull((new EPubArchiveReader())->readCover($file));
	}

	public function testReturnsNullWhenCoverItemHasEmptyHref(): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml(''),
		]);

		self::assertNull((new EPubArchiveReader())->readCover($file));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function unsafeCoverHrefProvider(): array {
		return [
			'absolute archive path' => ['/cover.png', 'not a safe archive-relative path'],
			'network path' => ['//host/cover.png', 'not a safe archive-relative path'],
			'backslash path' => ['images\\cover.png', 'not a safe archive-relative path'],
			'data URI' => ['data:image/png;base64,AAAA', 'not a safe archive-relative path'],
			'encoded NUL' => ['images/cover%00.png', 'invalid archive-relative path'],
			'encoded root escape' => ['%2e%2e/%2e%2e/cover.png', 'archive path escapes its root'],
		];
	}

	/**
	 * @dataProvider unsafeCoverHrefProvider
	 */
	public function testRejectsUnsafeCoverPathVariant(string $href, string $expectedMessage): void {
		$file = $this->createEpub([
			'META-INF/container.xml' => $this->containerXml(),
			'OEBPS/content.opf' => $this->packageXml($href),
		]);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage($expectedMessage);

		(new EPubArchiveReader())->readCover($file);
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
		$file = tempnam(sys_get_temp_dir(), 'epubviewer-edge-test-');
		self::assertIsString($file);
		$this->temporaryFiles[] = $file;

		return $file;
	}

	private function containerXml(string $packagePath = 'OEBPS/content.opf'): string {
		return '<?xml version="1.0"?>'
			. '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0">'
			. '<rootfiles><rootfile full-path="' . htmlspecialchars($packagePath, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" media-type="application/oebps-package+xml"/></rootfiles>'
			. '</container>';
	}

	private function packageXml(string $coverHref = 'images/cover.png', string $mediaType = 'image/png'): string {
		return '<?xml version="1.0"?>'
			. '<package xmlns="http://www.idpf.org/2007/opf" version="2.0">'
			. '<metadata><meta name="cover" content="cover-image"/></metadata>'
			. '<manifest><item id="cover-image" href="' . htmlspecialchars($coverHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" media-type="' . htmlspecialchars($mediaType, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/></manifest>'
			. '</package>';
	}
}
