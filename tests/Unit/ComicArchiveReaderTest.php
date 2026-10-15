<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Preview\ComicArchiveLimits;
use OCA\Epubviewer\Preview\ComicArchiveReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComicArchiveReaderTest extends TestCase {
	/** @return array<string, array{string, null|string}> */
	public static function pathProvider(): array {
		return [
			'normalizes separators' => ['Pages\\Page-2.PNG', 'Pages/Page-2.PNG'],
			'jpeg' => ['cover.JPG', 'cover.JPG'],
			'long jpeg extension' => ['cover.jpeg', 'cover.jpeg'],
			'png' => ['cover.PNG', 'cover.PNG'],
			'gif' => ['cover.GIF', 'cover.GIF'],
			'webp' => ['cover.WEBP', 'cover.WEBP'],
			'parent traversal' => ['../cover.png', null],
			'absolute path' => ['/cover.png', null],
			'Windows drive path' => ['C:/cover.png', null],
			'metadata directory' => ['__MACOSX/cover.png', null],
			'hidden component' => ['pages/.hidden/cover.png', null],
			'hidden file' => ['.cover.png', null],
			'unsupported extension' => ['cover.svg', null],
			'NUL byte' => ["cover\0.png", null],
		];
	}

	#[DataProvider('pathProvider')]
	public function testNormalizesAndFiltersCandidatePaths(string $path, ?string $expected): void {
		self::assertSame($expected, $this->reader()->candidate($path));
	}

	public function testRejectsOverlongAndOverdeepPaths(): void {
		$reader = $this->reader(new ComicArchiveLimits(maxEntryNameBytes: 16, maxPathDepth: 2));
		self::assertNull($reader->candidate('12345678901234567.png'));
		self::assertNull($reader->candidate('one/two/cover.png'));
	}

	public function testUsesCaseInsensitiveNaturalOrderingWithBytewiseTieBreak(): void {
		$reader = $this->reader();
		self::assertTrue($reader->before('page-2.png', 'page-10.png'));
		self::assertFalse($reader->before('page-10.png', 'page-2.png'));
		self::assertTrue($reader->before('Page.png', 'page.png'));
		self::assertFalse($reader->before('page.png', 'Page.png'));
		self::assertFalse($reader->before('page.png', 'page.png'));
	}

	public function testReadsExactDeclaredSizeAndClosesStream(): void {
		$stream = fopen('php://temp', 'w+b');
		self::assertIsResource($stream);
		fwrite($stream, 'cover');
		rewind($stream);

		self::assertSame('cover', $this->reader()->readStream($stream, 5));
		self::assertFalse(is_resource($stream));
	}

	public function testRejectsReadBeyondCoverLimitAndClosesStream(): void {
		$stream = fopen('php://temp', 'w+b');
		self::assertIsResource($stream);
		fwrite($stream, '12345');
		rewind($stream);

		$reader = $this->reader(new ComicArchiveLimits(maxCoverBytes: 4));
		try {
			$reader->readStream($stream, 5);
			self::fail('Expected the encoded cover limit to be enforced.');
		} catch (\UnexpectedValueException $exception) {
			self::assertStringContainsString('encoded cover limit', $exception->getMessage());
		}
		self::assertFalse(is_resource($stream));
	}

	public function testRejectsStreamLengthThatDisagreesWithMetadata(): void {
		$stream = fopen('php://temp', 'w+b');
		self::assertIsResource($stream);
		fwrite($stream, 'cover');
		rewind($stream);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('does not match its declared size');
		$this->reader()->readStream($stream, 6);
	}

	public function testValidatesImageBytesAndReturnsContentMime(): void {
		$data = file_get_contents(dirname(__DIR__, 2) . '/img/book.png');
		self::assertIsString($data);
		self::assertSame('image/png', $this->reader()->mime($data));
	}

	/** @return array<string, array{int, int}> */
	public static function unsafeSizeProvider(): array {
		return [
			'empty unpacked entry' => [0, 1],
			'negative packed size' => [1, -1],
			'cover over byte limit' => [101, 100],
			'zero packed size' => [100, 0],
			'unsafe compression ratio' => [100, 9],
		];
	}

	#[DataProvider('unsafeSizeProvider')]
	public function testRejectsUnsafeDeclaredSizesAndCompressionRatio(int $unpacked, int $packed): void {
		$reader = $this->reader(new ComicArchiveLimits(
			maxCoverBytes: 100,
			maxCompressionRatio: 10,
			minCompressionRatioBytes: 10,
		));

		$this->expectException(\UnexpectedValueException::class);
		$reader->assertSizes($unpacked, $packed);
	}

	private function reader(?ComicArchiveLimits $limits = null): TestComicArchiveReader {
		return new TestComicArchiveReader($limits);
	}
}

final class TestComicArchiveReader extends ComicArchiveReader {
	/** @return null|array{data: string, mime: string, path: string} */
	public function readCover(string $file): ?array {
		return null;
	}

	public function candidate(string $path): ?string {
		return $this->coverCandidatePath($path);
	}

	public function before(string $candidate, string $current): bool {
		return $this->candidateComesBefore($candidate, $current);
	}

	/** @param resource $stream */
	public function readStream($stream, int $declaredSize): string {
		return $this->readSelectedEntry($stream, $declaredSize, 'test cover');
	}

	public function mime(string $data): string {
		return $this->validatedCoverMime($data);
	}

	public function assertSizes(int $unpackedSize, int $packedSize): void {
		$this->assertSelectedEntrySafe($unpackedSize, $packedSize, 'test cover');
	}
}
