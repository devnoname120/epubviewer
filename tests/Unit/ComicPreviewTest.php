<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Preview\ComicArchiveLimits;
use OCA\Epubviewer\Preview\ComicArchiveReader;
use OCA\Epubviewer\Preview\ComicPreview;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use OCP\IImage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ComicPreviewTest extends TestCase {
	private string $temporaryPath;

	protected function setUp(): void {
		parent::setUp();
		$path = tempnam(sys_get_temp_dir(), 'comic-preview-');
		self::assertIsString($path);
		$this->temporaryPath = $path;
	}

	protected function tearDown(): void {
		if (is_file($this->temporaryPath)) {
			unlink($this->temporaryPath);
		}
		parent::tearDown();
	}

	public function testLocalArchiveBypassesTemporaryCopyLimit(): void {
		file_put_contents($this->temporaryPath, 'local');
		$reader = new ComicPreviewTestArchiveReader(4);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('debug');
		$file = $this->localFile($this->temporaryPath);
		$file->expects(self::never())->method('getSize');
		$file->expects(self::never())->method('fopen');

		$result = $this->provider($logger, $reader)->getThumbnail($file, 200, 300);

		self::assertNull($result);
		self::assertSame(1, $reader->readCount);
		self::assertSame($this->temporaryPath, $reader->lastFile);
		self::assertSame('local', $reader->lastContents);
		self::assertFileExists($this->temporaryPath);
	}

	public function testEncryptedLocalArchiveUsesBoundedTemporaryCopy(): void {
		$source = $this->memoryStream('data');
		$reader = new ComicPreviewTestArchiveReader(4);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('debug');
		$file = $this->createMock(File::class);
		$file->method('getInternalPath')->willReturn('files/comic.cbr');
		$file->method('getSize')->willReturn(4);
		$file->method('isEncrypted')->willReturn(true);
		$file->expects(self::never())->method('getStorage');
		$file->method('fopen')->with('r')->willReturn($source);

		self::assertNull($this->provider($logger, $reader)->getThumbnail($file, 200, 300));
		self::assertSame('data', $reader->lastContents);
		self::assertFalse(is_resource($source));
		self::assertFileDoesNotExist($this->temporaryPath);
	}

	public function testRemoteArchiveAtCopyLimitIsAccepted(): void {
		$source = $this->memoryStream('data');
		$reader = new ComicPreviewTestArchiveReader(4);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('debug');

		self::assertNull($this->provider($logger, $reader)->getThumbnail($this->remoteFile($source, 4), 200, 300));
		self::assertSame(1, $reader->readCount);
		self::assertSame('data', $reader->lastContents);
		self::assertFalse(is_resource($source));
		self::assertFileDoesNotExist($this->temporaryPath);
	}

	public function testRemoteArchiveOverReportedCopyLimitIsRejectedBeforeOpening(): void {
		$reader = new ComicPreviewTestArchiveReader(4);
		$logger = $this->warningLogger('The comic exceeds the temporary preview copy limit.');
		$file = $this->remoteFile(false, 5);
		$file->expects(self::never())->method('fopen');

		self::assertNull($this->provider($logger, $reader)->getThumbnail($file, 200, 300));
		self::assertSame(0, $reader->readCount);
	}

	public function testRemoteArchiveWithStaleUnderreportedSizeIsRejectedAfterBoundedCopy(): void {
		$source = $this->memoryStream('12345');
		$reader = new ComicPreviewTestArchiveReader(4);
		$logger = $this->warningLogger('The comic exceeds the temporary preview copy limit.');

		self::assertNull($this->provider($logger, $reader)->getThumbnail($this->remoteFile($source, 4), 200, 300));
		self::assertSame(0, $reader->readCount);
		self::assertFalse(is_resource($source));
		self::assertFileDoesNotExist($this->temporaryPath);
	}

	public function testNoCoverReturnsNullAndLogsAtDebugLevel(): void {
		file_put_contents($this->temporaryPath, 'local');
		$reader = new ComicPreviewTestArchiveReader(4);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('debug')
			->with('Comic archive has no previewable cover image.', ['app' => 'epubviewer']);
		$logger->expects(self::never())->method('warning');

		self::assertNull($this->provider($logger, $reader)->getThumbnail($this->localFile($this->temporaryPath), 200, 300));
	}

	public function testParserFailureLogsWarningAndDeletesTemporaryCopy(): void {
		$source = $this->memoryStream('data');
		$reader = new ComicPreviewTestArchiveReader(4, failure: new \UnexpectedValueException('bad archive'));
		$logger = $this->warningLogger('bad archive');

		self::assertNull($this->provider($logger, $reader)->getThumbnail($this->remoteFile($source, 4), 200, 300));
		self::assertFileDoesNotExist($this->temporaryPath);
	}

	public function testImageDecodeFailureLogsWarningAndDeletesTemporaryCopy(): void {
		$source = $this->memoryStream('data');
		$reader = new ComicPreviewTestArchiveReader(4, $this->cover('cover-bytes'));
		$image = $this->createMock(IImage::class);
		$image->expects(self::once())->method('loadFromData')->with('cover-bytes')->willReturn(false);
		$image->expects(self::never())->method('valid');
		$logger = $this->warningLogger('validated comic cover could not be decoded');

		self::assertNull($this->provider($logger, $reader, $image)->getThumbnail($this->remoteFile($source, 4), 200, 300));
		self::assertFileDoesNotExist($this->temporaryPath);
	}

	public function testValidCoverIsLoadedAndScaledDown(): void {
		file_put_contents($this->temporaryPath, 'local');
		$reader = new ComicPreviewTestArchiveReader(4, $this->cover('cover-bytes'));
		$image = $this->createMock(IImage::class);
		$gd = imagecreatetruecolor(1, 1);
		self::assertInstanceOf(\GdImage::class, $gd);
		$image->expects(self::once())->method('loadFromData')->with('cover-bytes')->willReturn($gd);
		$image->expects(self::once())->method('valid')->willReturn(true);
		$image->expects(self::once())->method('scaleDownToFit')->with(200, 300)->willReturn(true);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('debug');
		$logger->expects(self::never())->method('warning');

		self::assertSame(
			$image,
			$this->provider($logger, $reader, $image)->getThumbnail($this->localFile($this->temporaryPath), 200, 300),
		);
	}

	/** @return array{data: string, mime: string, path: string} */
	private function cover(string $data): array {
		return ['data' => $data, 'mime' => 'image/png', 'path' => 'page-01.png'];
	}

	private function provider(
		LoggerInterface $logger,
		ComicPreviewTestArchiveReader $reader,
		?IImage $image = null,
	): ComicPreviewTestProvider {
		return new ComicPreviewTestProvider(
			$logger,
			$reader,
			$this->temporaryPath,
			$image ?? $this->createMock(IImage::class),
		);
	}

	private function localFile(string $localPath): File&MockObject {
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->with('files/comic.cbz')->willReturn($localPath);
		$file = $this->createMock(File::class);
		$file->method('getInternalPath')->willReturn('files/comic.cbz');
		$file->method('isEncrypted')->willReturn(false);
		$file->method('getStorage')->willReturn($storage);
		return $file;
	}

	private function remoteFile(mixed $source, int $reportedSize): File&MockObject {
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(false);
		$file = $this->createMock(File::class);
		$file->method('getInternalPath')->willReturn('files/comic.cbz');
		$file->method('getSize')->willReturn($reportedSize);
		$file->method('isEncrypted')->willReturn(false);
		$file->method('getStorage')->willReturn($storage);
		$file->method('fopen')->with('r')->willReturn($source);
		return $file;
	}

	/** @return resource */
	private function memoryStream(string $data) {
		$stream = fopen('php://memory', 'w+b');
		self::assertIsResource($stream);
		fwrite($stream, $data);
		rewind($stream);
		return $stream;
	}

	private function warningLogger(string $messageFragment): LoggerInterface&MockObject {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with(
				'Failed to generate comic archive thumbnail for {file}, error: {error}.',
				self::callback(static fn (array $context): bool => $context['file'] === 'files/comic.cbz'
					&& str_contains($context['error'], $messageFragment)
					&& $context['exception'] instanceof \Throwable
					&& $context['app'] === 'epubviewer'),
			);
		return $logger;
	}
}

final class ComicPreviewTestProvider extends ComicPreview {
	public function __construct(
		LoggerInterface $logger,
		ComicArchiveReader $archiveReader,
		private string $temporaryPath,
		private IImage $image,
	) {
		parent::__construct($logger, $archiveReader);
	}

	public function getMimeType(): string {
		return '/^application\/x-test-comic$/';
	}

	protected function getTemporaryFile(): string|false {
		return $this->temporaryPath;
	}

	protected function createImage(): IImage {
		return $this->image;
	}
}

final class ComicPreviewTestArchiveReader extends ComicArchiveReader {
	public int $readCount = 0;
	public ?string $lastFile = null;
	public ?string $lastContents = null;

	/** @param null|array{data: string, mime: string, path: string} $cover */
	public function __construct(
		int $copyLimit,
		private ?array $cover = null,
		private ?\Throwable $failure = null,
	) {
		parent::__construct(new ComicArchiveLimits(maxTemporaryArchiveBytes: $copyLimit));
	}

	/** @return null|array{data: string, mime: string, path: string} */
	public function readCover(string $file): ?array {
		++$this->readCount;
		$this->lastFile = $file;
		if (is_file($file)) {
			$contents = file_get_contents($file);
			$this->lastContents = is_string($contents) ? $contents : null;
		}
		if ($this->failure !== null) {
			throw $this->failure;
		}
		return $this->cover;
	}
}
