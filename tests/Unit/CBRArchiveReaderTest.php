<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Preview\CBRArchiveReader;
use OCA\Epubviewer\Preview\ComicArchiveLimits;
use PHPUnit\Framework\TestCase;

final class CBRArchiveReaderTest extends TestCase {
	public function testThrowsControlledExceptionWithoutRarExtension(): void {
		if (extension_loaded('rar')) {
			self::markTestSkipped('This assertion requires PHP without the optional rar extension.');
		}

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('require the PHP RAR extension');
		(new CBRArchiveReader())->readCover($this->fixture('sample-rar4.rar'));
	}

	public function testReadsRar4ComicFixture(): void {
		$this->requireRarExtension();

		$cover = (new CBRArchiveReader())->readCover($this->fixture('comic-rar4.rar'));

		self::assertNotNull($cover);
		self::assertSame('page-01.PNG', $cover['path']);
		self::assertSame('image/png', $cover['mime']);
		self::assertSame($this->bookPng(), $cover['data']);
	}

	public function testSelectsFirstImageInNaturalPathOrderFromSolidRar5(): void {
		$this->requireRarExtension();

		$cover = (new CBRArchiveReader())->readCover($this->fixture('sample-rar5.rar'));

		self::assertNotNull($cover);
		self::assertSame('img/book.png', $cover['path']);
		self::assertSame('image/png', $cover['mime']);
		self::assertSame($this->bookPng(), $cover['data']);
	}

	public function testReturnsNullForRar4WithoutSupportedImages(): void {
		$this->requireRarExtension();

		self::assertNull((new CBRArchiveReader())->readCover($this->fixture('sample-rar4.rar')));
	}

	public function testRejectsArchiveOverEntryCountLimit(): void {
		$this->requireRarExtension();
		$limits = new ComicArchiveLimits(maxEntryCount: 1);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('entry-count limit');
		(new CBRArchiveReader($limits))->readCover($this->fixture('sample-rar5.rar'));
	}

	public function testRejectsSelectedCoverOverByteLimit(): void {
		$this->requireRarExtension();
		$limits = new ComicArchiveLimits(maxCoverBytes: strlen($this->bookPng()) - 1);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('encoded cover limit');
		(new CBRArchiveReader($limits))->readCover($this->fixture('comic-rar4.rar'));
	}

	public function testRejectsSelectedCoverOverCompressionRatioLimit(): void {
		$this->requireRarExtension();
		$limits = new ComicArchiveLimits(
			maxCoverBytes: 2 * 1024 * 1024,
			maxCompressionRatio: 2,
			minCompressionRatioBytes: 1,
		);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('compression-ratio limit');
		(new CBRArchiveReader($limits))->readCover($this->fixture('comic-compressed-rar4.rar'));
	}

	public function testRejectsSolidArchiveWorkOverPrefixLimit(): void {
		$this->requireRarExtension();
		$coverBytes = strlen($this->bookPng());
		$limits = new ComicArchiveLimits(
			maxCoverBytes: $coverBytes,
			maxSolidPrefixBytes: $coverBytes,
		);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('solid archive prefix limit');
		(new CBRArchiveReader($limits))->readCover($this->fixture('sample-rar5.rar'));
	}

	private function requireRarExtension(): void {
		if (!extension_loaded('rar')) {
			self::markTestSkipped('The optional rar extension is not installed.');
		}
	}

	private function fixture(string $name): string {
		return dirname(__DIR__) . '/fixtures/rar/' . $name;
	}

	private function bookPng(): string {
		$data = file_get_contents(dirname(__DIR__, 2) . '/img/book.png');
		self::assertIsString($data);
		return $data;
	}
}
