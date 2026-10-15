<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Preview;

use OCP\Files\File;
use OCP\IImage;
use OCP\Image;
use Psr\Log\LoggerInterface;

abstract class ComicPreview extends ProviderV2 {
	protected function __construct(
		private LoggerInterface $logger,
		private ComicArchiveReader $archiveReader,
	) {
		parent::__construct();
	}

	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		$internalPath = $file->getInternalPath();

		try {
			$temporaryCopy = $this->useTempFile($file);
			$copyLimit = $this->archiveReader->getMaxTemporaryArchiveBytes();
			if ($temporaryCopy && $file->getSize() > $copyLimit) {
				throw new \UnexpectedValueException('The comic exceeds the temporary preview copy limit.');
			}

			$localFile = $this->getLocalFile($file, $temporaryCopy ? $copyLimit + 1 : null);
			if ($localFile === false) {
				throw new \UnexpectedValueException('The comic could not be copied for preview generation.');
			}
			if ($temporaryCopy) {
				clearstatcache(true, $localFile);
				$copiedSize = filesize($localFile);
				if ($copiedSize === false || $copiedSize > $copyLimit) {
					throw new \UnexpectedValueException('The comic exceeds the temporary preview copy limit.');
				}
			}

			$cover = $this->archiveReader->readCover($localFile);
			if ($cover === null) {
				$this->logger->debug('Comic archive has no previewable cover image.', ['app' => 'epubviewer']);
				return null;
			}

			$image = $this->createImage();
			if ($image->loadFromData($cover['data']) === false || !$image->valid()) {
				throw new \UnexpectedValueException('The validated comic cover could not be decoded.');
			}
			$image->scaleDownToFit($maxX, $maxY);
			return $image;
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to generate comic archive thumbnail for {file}, error: {error}.', [
				'file' => $internalPath,
				'error' => $e->getMessage(),
				'exception' => $e,
				'app' => 'epubviewer',
			]);
			return null;
		} finally {
			$this->cleanTmpFiles();
		}
	}

	protected function createImage(): IImage {
		return new Image();
	}
}
