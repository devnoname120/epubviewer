<?php

namespace OCA\Epubviewer\Preview;

use OCP\Files\File;
use OCP\IImage;
use OCP\Image;
use Psr\Log\LoggerInterface;
use SebLucas\EPubMeta\EPub;

/**
 * Preview generator for .epub e-book files.
 */
class EPubPreview extends ProviderV2 {

	/**
	 * @param LoggerInterface $logger dependency-injected logger
	 */
	public function __construct(private LoggerInterface $logger) {
		parent::__construct();
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
		$image = new Image();

		try {
			$localFile = $this->getLocalFile($file);
			if ($localFile === false) {
				$this->logger->warning('Could not generate EPUB file thumbnail for {file} because the local file is unavailable.', ['file' => $internalPath]);
				return null;
			}

			$epub = new EPub($localFile);
			$coverInfo = $epub->getCoverInfo();
			if (!$coverInfo['found']) {
				$this->logger->debug('EPUB file {file} parsed successfully, but no cover image was found to generate a thumbnail.', ['file' => $internalPath]);
				return null;
			}

			// Found a cover, so attempt to convert it to an OC_Image.
			$image->loadFromData($coverInfo['data']);
			if (!$image->valid()) {
				$this->logger->warning('EPUB file {file} contains cover \'{coverPath}\' (MIME: \'{coverMime}\') but it could not be loaded as a valid image, so no thumbnail is generated.', [
					'file' => $internalPath,
					'coverPath' => $coverInfo['found'],
					'coverMime' => $coverInfo['mime']
				]);
				return null;
			}
		} catch (\Exception $e) {
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

		// Scale image to fit to the maximum dimensions if necessary.
		$image->scaleDownToFit($maxX, $maxY);

		return $image;
	}
}
