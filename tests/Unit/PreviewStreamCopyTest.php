<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Preview\ProviderV2;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use OCP\IImage;
use PHPUnit\Framework\TestCase;

final class PreviewStreamCopyTest extends TestCase {
	private string $temporaryPath;
	private PreviewStreamCopyProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$path = tempnam(sys_get_temp_dir(), 'epub-preview-copy-');
		self::assertIsString($path);
		$this->temporaryPath = $path;
		$this->provider = new PreviewStreamCopyProvider($path);
	}

	protected function tearDown(): void {
		$this->provider->cleanup();
		if (is_file($this->temporaryPath)) {
			unlink($this->temporaryPath);
		}
		parent::tearDown();
	}

	public function testCopyRespectsItsByteLimitAndClosesTheSource(): void {
		$source = fopen('php://memory', 'w+b');
		self::assertIsResource($source);
		fwrite($source, '0123456789');
		rewind($source);
		self::assertSame($this->temporaryPath, $this->provider->copy($this->file($source), 4));
		self::assertSame('0123', file_get_contents($this->temporaryPath));
		self::assertFalse(is_resource($source));
	}

	public function testCopyWithoutALimitPreservesTheCompleteStream(): void {
		$source = fopen('php://memory', 'w+b');
		self::assertIsResource($source);
		$content = str_repeat('a page of a publication', 1000);
		fwrite($source, $content);
		rewind($source);
		self::assertSame($this->temporaryPath, $this->provider->copy($this->file($source), null));
		self::assertSame($content, file_get_contents($this->temporaryPath));
		self::assertFalse(is_resource($source));
	}

	public function testZeroByteLimitKeepsTheExistingUnlimitedCopyBehavior(): void {
		$source = fopen('php://memory', 'w+b');
		self::assertIsResource($source);
		fwrite($source, '0123456789');
		rewind($source);
		self::assertSame($this->temporaryPath, $this->provider->copy($this->file($source), 0));
		self::assertSame('0123456789', file_get_contents($this->temporaryPath));
	}

	public function testLocalFilesAreReturnedWithoutCopyingOrDeletingThem(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->with('files/book.epub')->willReturn($this->temporaryPath);
		$file = $this->createMock(File::class);
		$file->method('isEncrypted')->willReturn(false);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/book.epub');
		$file->expects(self::never())->method('fopen');
		self::assertSame($this->temporaryPath, $this->provider->copy($file, 4));
		$this->provider->cleanup();
		self::assertFileExists($this->temporaryPath);
	}

	public function testFailedSourceOpeningStillCleansUpTheTemporaryFile(): void {
		self::assertFalse($this->provider->copy($this->file(false), 4));
		$this->provider->cleanup();
		self::assertFileDoesNotExist($this->temporaryPath);
	}

	public function testReadFailureReturnsFalseAndClosesTheStream(): void {
		// Opening a directory succeeds on the supported Unix test platforms; reading it fails.
		$source = fopen(sys_get_temp_dir(), 'r');
		self::assertIsResource($source);
		$warnings = [];
		set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
			$warnings[] = $message;
			return true;
		});
		try {
			self::assertFalse($this->provider->copy($this->file($source), 4));
		} finally {
			restore_error_handler();
		}
		self::assertNotEmpty($warnings);
		self::assertFalse(is_resource($source));
		$this->provider->cleanup();
		self::assertFileDoesNotExist($this->temporaryPath);
	}

	public function testWriteFailureReturnsFalseAndClosesBothStreams(): void {
		self::assertTrue(stream_wrapper_register('epub-preview-write-failure', PreviewFailedWriteStream::class));
		PreviewFailedWriteStream::$closed = false;
		$provider = new PreviewStreamCopyProvider('epub-preview-write-failure://target');
		$source = fopen('php://memory', 'w+b');
		self::assertIsResource($source);
		fwrite($source, '0123456789');
		rewind($source);
		set_error_handler(static fn (): bool => true);
		try {
			self::assertFalse($provider->copy($this->file($source), 4));
			self::assertFalse(is_resource($source));
			self::assertTrue(PreviewFailedWriteStream::$closed);
		} finally {
			$provider->cleanup();
			restore_error_handler();
			stream_wrapper_unregister('epub-preview-write-failure');
		}
	}

	private function file(mixed $source): File {
		$file = $this->createMock(File::class);
		$file->method('isEncrypted')->willReturn(true);
		$file->method('fopen')->with('r')->willReturn($source);
		return $file;
	}
}

final class PreviewStreamCopyProvider extends ProviderV2 {
	public function __construct(
		private string $temporaryPath,
	) {
		parent::__construct();
	}

	protected function getTemporaryFile(): string|false {
		return $this->temporaryPath;
	}

	public function copy(File $file, ?int $limit): string|false {
		return $this->getLocalFile($file, $limit);
	}

	public function cleanup(): void {
		$this->cleanTmpFiles();
	}

	public function getMimeType(): string {
		return '/^application\/epub\+zip$/';
	}

	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		return null;
	}
}

final class PreviewFailedWriteStream {
	public mixed $context;
	public static bool $closed = false;

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		return true;
	}

	public function stream_write(string $data): int {
		return 0;
	}

	public function stream_stat(): array {
		return [];
	}

	public function stream_close(): void {
		self::$closed = true;
	}

	public function unlink(string $path): bool {
		return true;
	}
}
