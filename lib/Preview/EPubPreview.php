<?php
/**
 *
 * @author Sebastien Marinier <seb@smarinier.net>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Epubviewer\Preview;

use OC\Preview\ProviderV2;
use OCP\Files\File;
use OCP\IImage;
use OCP\Image;
use Psr\Log\LoggerInterface;
use SebLucas\EPubMeta\EPub;

/**
 * Preview generator for .epub e-book files.
 */
class EPubPreview extends ProviderV2 {

	/** @var LoggerInterface dependency-injected logger */
	private LoggerInterface $logger;

	/**
	 * @param LoggerInterface $logger dependency-injected logger
	 */
	public function __construct(LoggerInterface $logger) {
		$this->logger = $logger;
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
			$localFile = $this->getLocalFile($file);
			if ($localFile === false) {
				$this->logger->warning('Could not generate EPUB file thumbnail for {file} because the local file is unavailable.', ['file' => $internalPath]);
				return null;
			}

			$epub = new EPub($localFile);

			// CoverInfo is an associative array with the following keys:
			// - mime: the mime type of the cover image in the data key, or image/gif if none found
			// - data: the data of the cover image, or a transparent GIF pixel if none found
			// - found: a string containing the cover archive file path, or false if none found
			$coverInfo = $epub->getCoverInfo();
			if (!$coverInfo['found']) {
				$this->logger->debug('EPUB file {file} parsed successfully, but no cover image was found to generate a thumbnail.', ['file' => $internalPath]);
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

		// Found a cover, so attempt to convert it to an OC_Image.
		$image = new Image();
		$image->loadFromData($coverInfo['data']);
		if (!$image->valid()) {
			$this->logger->warning('EPUB file {file} contains cover \'{cover}\' (MIME: \'{mime}\') but it could not be loaded a valid image, so no thumbnail is generated.', [
				'file' => $internalPath,
				'cover' => $coverInfo['found'],
				'mime' => $coverInfo['mime']
			]);
			return null;
		}

		// Scale image to fit to the maximum dimensions if necessary.
		$image->scaleDownToFit($maxX, $maxY);

		return $image;
	}
}
