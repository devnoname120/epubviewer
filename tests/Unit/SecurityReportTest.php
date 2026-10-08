<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Controller\PageController;
use OCA\Epubviewer\Preview\EPubArchiveLimits;
use OCA\Epubviewer\Preview\ImageHeaderPreflight;
use OCA\Epubviewer\Preview\ZipMetadataPreflight;
use OCA\Epubviewer\Service\PublicSharePath;
use OCA\Epubviewer\Service\ReaderPath;
use OCA\Epubviewer\Service\ReaderResponseBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SecurityReportTest extends TestCase {
	/** @return array<string, array{string}> */
	public static function unsafePaths(): array {
		return array_map(static fn (string $segment): array => [$segment], [
			'dot' => '.', 'parent' => '..', 'encoded dot' => '%2e', 'encoded parent' => '%2E%2e',
			'encoded slash' => 'a%2fb', 'backslash' => 'a\\b', 'encoded backslash' => '%5c',
			'nul' => '%00', 'literal nul' => "a\0b", 'empty segment' => 'a//b',
		]);
	}

	#[DataProvider('unsafePaths')]
	public function testRejectsUnsafePublicSegments(string $path): void {
		$this->expectException(NotFoundException::class);
		(new PublicSharePath())->parse('/public.php/dav/files/token/' . $path);
	}

	#[DataProvider('unsafePaths')]
	public function testRejectsUnsafeAuthenticatedSegmentsBeforeResolution(string $path): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([
			['file', null, '/remote.php/dav/files/alice/' . $path],
			['type', null, 'application/epub+zip'],
		]);
		$root = $this->createMock(IRootFolder::class);
		$root->expects(self::never())->method('getUserFolder');
		$builder = $this->createMock(ReaderResponseBuilder::class);
		$builder->expects(self::never())->method('build');
		$controller = new PageController('epubviewer', $request, $root, new PublicSharePath(), $builder, 'alice');
		$this->expectException(NotFoundException::class);
		$controller->showReader();
	}

	/** @return array<string, array{string}> */
	public static function escapedNodes(): array {
		return [
			'outside root' => ['/alice/files/Other/book.epub'],
			'prefix collision' => ['/alice/files/Library-other/book.epub'],
			'non-canonical path' => ['/alice/files/Library/../book.epub'],
		];
	}

	#[DataProvider('escapedNodes')]
	public function testRejectsOutOfRootResolverResult(string $path): void {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/alice/files/Library');
		$node = $this->createMock(File::class);
		$node->method('getPath')->willReturn($path);
		$this->expectException(NotFoundException::class);
		ReaderPath::assertContained($root, $node);
	}

	public function testPreservesLiteralPercentNamesAfterResolution(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/alice/files/Library');
		$node = $this->createMock(File::class);
		$node->method('getPath')->willReturn('/alice/files/Library/%2e%2e.epub');
		ReaderPath::assertContained($root, $node);
		self::assertSame(['%2e%2e.epub'], ReaderPath::segments('%252e%252e.epub'));
	}

	public function testCentralDirectoryBudgetRunsBeforeZipOpening(): void {
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('more than 1 archive entries before ZIP opening');
		ZipMetadataPreflight::check($this->fixture(), new EPubArchiveLimits(maxEntryCount: 1));
	}

	public function testCentralDirectoryByteBudget(): void {
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('central directory is oversized');
		ZipMetadataPreflight::check($this->fixture(), new EPubArchiveLimits(maxCentralDirectoryBytes: 1));
	}

	public function testDoesNotTrustUnderreportedEntryCount(): void {
		$data = file_get_contents($this->fixture());
		self::assertIsString($data);
		$offset = strrpos($data, "PK\x05\x06");
		self::assertIsInt($offset);
		$data = substr_replace($data, "\0\0\0\0", $offset + 8, 4);
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('entry count is inconsistent');
		$this->checkTemporaryArchive($data);
	}

	public function testAcceptsBoundedZip64Metadata(): void {
		$data = file_get_contents($this->fixture());
		self::assertIsString($data);
		$offset = strrpos($data, "PK\x05\x06");
		self::assertIsInt($offset);
		$end = substr($data, $offset);
		$count = unpack('v', substr($end, 10, 2))[1];
		$size = unpack('V', substr($end, 12, 4))[1];
		$start = unpack('V', substr($end, 16, 4))[1];
		// Explicit fields avoid native-endian 64-bit packing.
		$record = "PK\x06\x06" . pack('VVvv', 44, 0, 45, 45)
			. pack('VVVVVVVVVV', 0, 0, $count, 0, $count, 0, $size, 0, $start, 0);
		$locator = "PK\x06\x07" . pack('VVVV', 0, $offset, 0, 1);
		$end = substr_replace($end, str_repeat("\xff", 12), 8, 12);
		$this->checkTemporaryArchive(substr($data, 0, $offset) . $record . $locator . $end);
		self::assertTrue(true);
	}

	public function testRejectsMalformedEndRecord(): void {
		$this->expectException(\UnexpectedValueException::class);
		$this->checkTemporaryArchive(str_repeat("\0", 64));
	}

	public function testAcceptsNormalPngCoverWithoutDecoding(): void {
		$data = file_get_contents(dirname(__DIR__, 2) . '/img/book.png');
		self::assertIsString($data);
		ImageHeaderPreflight::check($data, new EPubArchiveLimits());
		self::assertTrue(true);
	}

	public function testRejectsCoverPixelsBeforeDecoder(): void {
		$data = file_get_contents(dirname(__DIR__, 2) . '/img/book.png');
		self::assertIsString($data);
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('pixel limit');
		ImageHeaderPreflight::check($data, new EPubArchiveLimits(maxImagePixels: 1));
	}

	public function testRejectsAnimatedGifCover(): void {
		$data = file_get_contents(dirname(__DIR__, 2) . '/img/loading.gif');
		self::assertIsString($data);
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Animated');
		ImageHeaderPreflight::check($data, new EPubArchiveLimits());
	}

	public function testRejectsUnknownCoverHeader(): void {
		$this->expectException(\UnexpectedValueException::class);
		ImageHeaderPreflight::check('not an image', new EPubArchiveLimits());
	}

	private function fixture(): string {
		return __DIR__ . '/../fixtures/epub/epub2-metadata-cover.epub';
	}

	private function checkTemporaryArchive(string $data): void {
		$file = tempnam(sys_get_temp_dir(), 'epub-preflight-');
		self::assertIsString($file);
		try {
			file_put_contents($file, $data);
			ZipMetadataPreflight::check($file, new EPubArchiveLimits());
		} finally {
			unlink($file);
		}
	}
}
