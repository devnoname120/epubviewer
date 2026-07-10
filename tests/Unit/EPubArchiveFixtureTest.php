<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Preview\EPubArchiveLimits;
use OCA\Epubviewer\Preview\EPubArchiveReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class EPubArchiveFixtureTest extends TestCase {
	private const FIXTURE_DIRECTORY = __DIR__ . '/../fixtures/epub';
	private const EPUB_MIMETYPE = 'application/epub+zip';
	private const PNG_COVER_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAD91JpzAAAAFElEQVR42mP4z8DAwMDAxMDAwMAAAAwAAf2K6LkAAAAASUVORK5CYII=';
	private const JPEG_COVER_BASE64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EH//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EH//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EH//2Q==';

	/** @var list<string> */
	private array $temporaryDirectories = [];

	protected function tearDown(): void {
		foreach ($this->temporaryDirectories as $directory) {
			$files = glob($directory . '/*');
			if (is_array($files)) {
				foreach ($files as $file) {
					if (is_file($file)) {
						@unlink($file);
					}
				}
			}
			@rmdir($directory);
		}

		parent::tearDown();
	}

	/**
	 * @return array<string, array{string, string, string, string, string}>
	 */
	public static function coverFixtureProvider(): array {
		return [
			'EPUB 2 metadata cover' => [
				'epub2-metadata-cover.epub',
				'OEBPS/images/cover.png',
				'image/png',
				self::PNG_COVER_BASE64,
				'032ba36a2f8da7bc9c23bc06ff3987de03bcd2c2d97d1aeb89d8b1530e823de7',
			],
			'EPUB 3 cover-image token among multiple properties after stale legacy pointer' => [
				'epub3-cover-image-properties.epub',
				'EPUB/assets/cover.jpg',
				'image/jpeg',
				self::JPEG_COVER_BASE64,
				'b6694b7a1b1eaa1228fcea9a46d4987cd406b650ce2e3c59e62be638ac166ced',
			],
			'nested package and encoded relative cover path' => [
				'nested-percent-encoded-cover.epub',
				'Books/Artwork/Front Cover.png',
				'image/png',
				self::PNG_COVER_BASE64,
				'032ba36a2f8da7bc9c23bc06ff3987de03bcd2c2d97d1aeb89d8b1530e823de7',
			],
			'large unrelated chapter and small cover' => [
				'epub3-large-chapter-small-cover.epub',
				'EPUB/images/cover.png',
				'image/png',
				self::PNG_COVER_BASE64,
				'032ba36a2f8da7bc9c23bc06ff3987de03bcd2c2d97d1aeb89d8b1530e823de7',
			],
		];
	}

	/**
	 * @dataProvider coverFixtureProvider
	 */
	public function testReadsExpectedCoverFromFixture(
		string $fixture,
		string $expectedPath,
		string $expectedMime,
		string $expectedBase64,
		string $expectedSha256,
	): void {
		$expectedData = base64_decode($expectedBase64, true);
		self::assertIsString($expectedData);

		$result = (new EPubArchiveReader())->readCover($this->fixturePath($fixture));

		self::assertNotNull($result);
		self::assertSame($expectedPath, $result['path']);
		self::assertSame($expectedMime, $result['mime']);
		self::assertSame($expectedData, $result['data']);
		self::assertSame($expectedSha256, hash('sha256', $result['data']));
	}

	public function testReturnsNullForPublicationWithoutCover(): void {
		self::assertNull((new EPubArchiveReader())->readCover($this->fixturePath('no-cover.epub')));
	}

	public function testIgnoresUnrelatedLargeChapterWhenReadingSmallCover(): void {
		$limits = new EPubArchiveLimits(
			maxArchiveBytes: 16 * 1024,
			maxEntryCount: 10,
			maxTotalUncompressedBytes: 2 * 1024,
			maxEntryUncompressedBytes: 1024,
			maxCompressionRatio: 200,
			minCompressionRatioBytes: 1024,
			maxContainerXmlBytes: 512,
			maxPackageDocumentBytes: 1024,
			maxCoverBytes: 128,
		);
		$fixture = $this->fixturePath('epub3-large-chapter-small-cover.epub');
		$zip = new ZipArchive();
		self::assertTrue($zip->open($fixture, ZipArchive::RDONLY | ZipArchive::CHECKCONS));
		try {
			$chapter = $zip->statName('EPUB/chapter.xhtml');
			self::assertIsArray($chapter);
			self::assertGreaterThan(4 * 1024, $chapter['size']);
		} finally {
			$zip->close();
		}

		$result = (new EPubArchiveReader($limits))->readCover($fixture);

		self::assertNotNull($result);
		self::assertSame('EPUB/images/cover.png', $result['path']);
		self::assertSame('image/png', $result['mime']);
		self::assertSame(
			'032ba36a2f8da7bc9c23bc06ff3987de03bcd2c2d97d1aeb89d8b1530e823de7',
			hash('sha256', $result['data']),
		);
	}

	public function testToleratesQueryAndFragmentOnEncodedCoverHref(): void {
		require_once self::FIXTURE_DIRECTORY . '/build.php';

		$entries = \OCA\Epubviewer\Tests\Fixtures\Epub\publicationEntries(
			\OCA\Epubviewer\Tests\Fixtures\Epub\containerXml('OEBPS/content.opf'),
			[
				[
					'name' => 'OEBPS/content.opf',
					'data' => \OCA\Epubviewer\Tests\Fixtures\Epub\basicPackageXml(
						'00000000-0000-4000-8000-00000000f701',
						'images/Front%20Cover.png?edition=test#front',
					),
				],
				[
					'name' => 'OEBPS/images/Front Cover.png',
					'data' => \OCA\Epubviewer\Tests\Fixtures\Epub\fixturePngCover(),
				],
			],
		);
		$temporaryDirectory = $this->temporaryDirectory();
		$file = $temporaryDirectory . '/query-fragment-tolerance.epub';
		$archive = \OCA\Epubviewer\Tests\Fixtures\Epub\buildStoredZip($entries);
		self::assertSame(strlen($archive), file_put_contents($file, $archive));

		$result = (new EPubArchiveReader())->readCover($file);

		self::assertNotNull($result);
		self::assertSame('OEBPS/images/Front Cover.png', $result['path']);
		self::assertSame('image/png', $result['mime']);
		self::assertSame(
			'032ba36a2f8da7bc9c23bc06ff3987de03bcd2c2d97d1aeb89d8b1530e823de7',
			hash('sha256', $result['data']),
		);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function rejectedFixtureProvider(): array {
		return [
			'external DTD and entity' => [
				'rejected-dtd.epub',
				'prohibited DTD or entity declaration',
			],
			'external cover URL' => [
				'rejected-external-cover.epub',
				'not a safe archive-relative path',
			],
			'cover path escaping archive root' => [
				'rejected-escaping-cover.epub',
				'archive path escapes its root',
			],
		];
	}

	/**
	 * @dataProvider rejectedFixtureProvider
	 */
	public function testRejectsUnsafeFixture(string $fixture, string $expectedMessage): void {
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage($expectedMessage);

		(new EPubArchiveReader())->readCover($this->fixturePath($fixture));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function allFixtureProvider(): array {
		return [
			'EPUB 2 metadata cover' => ['epub2-metadata-cover.epub'],
			'EPUB 3 cover-image properties' => ['epub3-cover-image-properties.epub'],
			'nested percent-encoded cover' => ['nested-percent-encoded-cover.epub'],
			'large chapter and small cover' => ['epub3-large-chapter-small-cover.epub'],
			'no cover' => ['no-cover.epub'],
			'rejected DTD' => ['rejected-dtd.epub'],
			'rejected external cover' => ['rejected-external-cover.epub'],
			'rejected escaping cover' => ['rejected-escaping-cover.epub'],
		];
	}

	/**
	 * @dataProvider allFixtureProvider
	 */
	public function testFixtureHasRequiredEpubZipStructure(string $fixture): void {
		$zip = new ZipArchive();
		self::assertTrue($zip->open($this->fixturePath($fixture), ZipArchive::RDONLY | ZipArchive::CHECKCONS));

		try {
			$mimetype = $zip->statIndex(0);
			self::assertIsArray($mimetype);
			self::assertSame('mimetype', $mimetype['name']);
			self::assertSame(ZipArchive::CM_STORE, $mimetype['comp_method']);
			self::assertSame(self::EPUB_MIMETYPE, $zip->getFromIndex(0));
			self::assertNotFalse($zip->locateName('META-INF/container.xml'));
		} finally {
			$zip->close();
		}
	}

	public function testCommittedFixtureCorpusIsByteForByteReproducible(): void {
		require_once self::FIXTURE_DIRECTORY . '/build.php';

		$temporaryDirectory = $this->temporaryDirectory();
		$generated = \OCA\Epubviewer\Tests\Fixtures\Epub\buildFixtures($temporaryDirectory);
		$committed = glob(self::FIXTURE_DIRECTORY . '/*.epub');
		self::assertIsArray($committed);
		$committed = array_map('basename', $committed);
		sort($committed);
		$sortedGenerated = $generated;
		sort($sortedGenerated);

		self::assertSame($committed, $sortedGenerated);
		foreach ($generated as $fixture) {
			$expected = file_get_contents($this->fixturePath($fixture));
			$actual = file_get_contents($temporaryDirectory . '/' . $fixture);
			self::assertIsString($expected);
			self::assertIsString($actual);
			self::assertSame(hash('sha256', $expected), hash('sha256', $actual), $fixture);
			self::assertSame($expected, $actual, $fixture);
		}
	}

	private function fixturePath(string $fixture): string {
		return self::FIXTURE_DIRECTORY . '/' . $fixture;
	}

	private function temporaryDirectory(): string {
		$directory = sys_get_temp_dir() . '/epubviewer-fixtures-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($directory, 0700, true));
		$this->temporaryDirectories[] = $directory;

		return $directory;
	}
}
