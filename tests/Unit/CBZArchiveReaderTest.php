<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Preview\CBZArchiveReader;
use OCA\Epubviewer\Preview\ComicArchiveLimits;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class CBZArchiveReaderTest extends TestCase {
	/** @var list<string> */
	private array $temporaryFiles = [];

	protected function tearDown(): void {
		foreach ($this->temporaryFiles as $file) {
			@unlink($file);
		}
		$this->temporaryFiles = [];
	}

	public function testSelectsFirstImageInNaturalPathOrder(): void {
		$file = $this->createCbz([
			['page-10.png', $this->png()],
			['page-2.PNG', $this->png()],
		]);

		$cover = (new CBZArchiveReader())->readCover($file);

		self::assertNotNull($cover);
		self::assertSame('page-2.PNG', $cover['path']);
		self::assertSame('image/png', $cover['mime']);
		self::assertSame($this->png(), $cover['data']);
	}

	public function testReturnsNullWithoutSupportedImages(): void {
		$file = $this->createCbz([
			['ComicInfo.xml', '<ComicInfo/>'],
			['notes.txt', 'No cover here.'],
		]);

		self::assertNull((new CBZArchiveReader())->readCover($file));
	}

	public function testIgnoresUnsafeAndMetadataPaths(): void {
		$file = $this->createCbz([
			['../cover.png', $this->png()],
			['__MACOSX/cover.png', $this->png()],
			['pages/.hidden.png', $this->png()],
			['cover.svg', '<svg/>'],
			['pages/001.png', $this->png()],
		]);

		$cover = (new CBZArchiveReader())->readCover($file);

		self::assertNotNull($cover);
		self::assertSame('pages/001.png', $cover['path']);
	}

	public function testUsesEncodedBytesForMimeInsteadOfExtension(): void {
		$file = $this->createCbz([['cover.jpg', $this->png()]]);

		$cover = (new CBZArchiveReader())->readCover($file);

		self::assertNotNull($cover);
		self::assertSame('image/png', $cover['mime']);
	}

	public function testDoesNotFallThroughWhenFirstImageIsMalformed(): void {
		$file = $this->createCbz([
			['page-1.png', 'not an image'],
			['page-2.png', $this->png()],
		]);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('unknown or invalid image dimensions');
		(new CBZArchiveReader())->readCover($file);
	}

	public function testRejectsSelectedImageOverByteLimit(): void {
		$data = $this->png();
		$file = $this->createCbz([['cover.png', $data]]);
		$limits = new ComicArchiveLimits(maxCoverBytes: strlen($data) - 1);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('encoded cover limit');
		(new CBZArchiveReader($limits))->readCover($file);
	}

	public function testRejectsSelectedImageOverCompressionRatioLimit(): void {
		$file = $this->createCbz([['cover.png', str_repeat('A', 4096)]]);
		$limits = new ComicArchiveLimits(
			maxCoverBytes: 8192,
			maxCompressionRatio: 2,
			minCompressionRatioBytes: 1,
		);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('compression-ratio limit');
		(new CBZArchiveReader($limits))->readCover($file);
	}

	public function testRejectsEncryptedSelectedImage(): void {
		$file = $this->temporaryFile();
		$zip = new ZipArchive();
		self::assertTrue($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE));
		self::assertTrue($zip->addFromString('cover.png', $this->png()));
		self::assertTrue($zip->setPassword('secret'));
		if (!$zip->setEncryptionName('cover.png', ZipArchive::EM_AES_256)) {
			$zip->close();
			self::markTestSkipped('The local libzip build does not support ZIP encryption.');
		}
		self::assertTrue($zip->close());

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('encrypted');
		(new CBZArchiveReader())->readCover($file);
	}

	public function testRejectsCentralDirectoryOverLimit(): void {
		$file = $this->createCbz([['cover.png', $this->png()]]);
		$limits = new ComicArchiveLimits(maxCentralDirectoryBytes: 1);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('central directory is oversized');
		(new CBZArchiveReader($limits))->readCover($file);
	}

	public function testRejectsDuplicateNamesAsInconsistent(): void {
		$file = $this->writeStoredZip([
			['cover.png', $this->png()],
			['cover.png', 'not the selected cover'],
		]);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('consistent ZIP archive');
		(new CBZArchiveReader())->readCover($file);
	}

	/** @param list<array{string, string}> $entries */
	private function createCbz(array $entries): string {
		$file = $this->temporaryFile();
		$zip = new ZipArchive();
		self::assertTrue($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE));

		foreach ($entries as [$name, $data]) {
			self::assertTrue($zip->addFromString($name, $data));
		}

		self::assertTrue($zip->close());
		return $file;
	}

	/** @param list<array{string, string}> $entries */
	private function writeStoredZip(array $entries): string {
		$file = $this->temporaryFile();
		$local = '';
		$central = '';
		$offset = 0;

		foreach ($entries as [$name, $data]) {
			$crc = crc32($data);
			$size = strlen($data);
			$nameLength = strlen($name);
			$header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0);
			$local .= $header . $name . $data;
			$central .= pack(
				'VvvvvvvVVVvvvvvVV',
				0x02014b50,
				20,
				20,
				0,
				0,
				0,
				0,
				$crc,
				$size,
				$size,
				$nameLength,
				0,
				0,
				0,
				0,
				0,
				$offset,
			) . $name;
			$offset = strlen($local);
		}

		$count = count($entries);
		$end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);
		self::assertSame(strlen($local . $central . $end), file_put_contents($file, $local . $central . $end));

		return $file;
	}

	private function temporaryFile(): string {
		$file = tempnam(sys_get_temp_dir(), 'epubviewer-cbz-test-');
		self::assertIsString($file);
		$this->temporaryFiles[] = $file;
		return $file;
	}

	private function png(): string {
		$data = file_get_contents(dirname(__DIR__, 2) . '/img/book.png');
		self::assertIsString($data);
		return $data;
	}
}
