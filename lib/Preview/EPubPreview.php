<?php

namespace OCA\Epubviewer\Preview;

use OCP\Files\File;
use OCP\IImage;
use OCP\Image;
use Psr\Log\LoggerInterface;

/**
 * Preview generator for .epub e-book files.
 */
class EPubPreview extends ProviderV2 {
	private EPubArchiveReader $archiveReader;

	/**
	 * @param LoggerInterface $logger dependency-injected logger
	 */
	public function __construct(
		private LoggerInterface $logger,
	) {
		parent::__construct();
		$this->archiveReader = new EPubArchiveReader();
	}

	/**
	 * {@inheritDoc}
	 */
	public function getMimeType(): string {
		return '/^application\/epub\+zip$/';
	}

	/**
	 * @inheritDoc
	 */
	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		$internalPath = $file->getInternalPath();

		try {
			if ($file->getSize() > $this->archiveReader->getMaxArchiveBytes()) {
				throw new \UnexpectedValueException(sprintf(
					'The EPUB exceeds the %d-byte preview input limit.',
					$this->archiveReader->getMaxArchiveBytes(),
				));
			}

			// Copy at most one byte beyond the limit so a stale remote size cannot
			// cause an unbounded temporary-file write before validation.
			$localFile = $this->getLocalFile($file, $this->archiveReader->getMaxArchiveBytes() + 1);
			if ($localFile === false) {
				$this->logger->warning('Could not generate EPUB file thumbnail for {file} because the local file is unavailable.', ['file' => $internalPath]);
				return null;
			}

			$coverInfo = $this->archiveReader->readCover($localFile);
			if ($coverInfo === null) {
				$this->logger->debug('EPUB file {file} parsed successfully, but no cover image was found to generate a thumbnail.', ['file' => $internalPath]);
				return null;
			}

			// Found a cover, so attempt to convert it to an OC\Image.
			$image = new Image();
			$image->loadFromData($coverInfo['data']);
			if (!$image->valid()) {
				$this->logger->warning('EPUB file {file} contains cover \'{coverPath}\' (MIME: \'{coverMime}\') but it could not be loaded as a valid image, so no thumbnail is generated.', [
					'file' => $internalPath,
					'coverPath' => $coverInfo['path'],
					'coverMime' => $coverInfo['mime']
				]);
				return null;
			}

			// Scale image to fit to the maximum dimensions if necessary.
			$image->scaleDownToFit($maxX, $maxY);

			return $image;
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to generate thumbnail for EPUB file {file}, error: {error}.', [
				'file' => $internalPath,
				'error' => $e->getMessage(),
				'exception' => $e
			]);
			return null;
		} finally {
			// Clean up any potential temporary files created by getLocalFile()
			$this->cleanTmpFiles();
		}
	}
}
