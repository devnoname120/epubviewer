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

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Preview\IProviderV2;
use SebLucas\EPubMeta\EPub;

/**
 * Preview generator for .epub e-book files.
 */
class EPubPreview implements IProviderV2 {

	/**
	 * {@inheritDoc}
	 */
	public function getMimeType(): string {
		return '/application\/epub\+zip/';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isAvailable(FileInfo $file): bool {
		return $file->getSize() > 0;
	}

	/**
	 * @inheritDoc
	 */
	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		try {
			$epub = new EPub($file->getPath());
			$coverInfo = $epub->getCoverInfo();
			if (!$coverInfo["found"]) {
				return null; // Successfully parsed, but no cover image.
			}
		} catch (\Exception $e) {
			return null; // Parse error, so treat as no cover image.
		}

		// Found a cover, so attempt to convert it to an OC_Image.
		$image = new \OC_Image();
		$image->loadFromData($coverInfo["data"]);
		if (!$image->valid()) {
			return null; // Invalid image, so don't provide a cover image.
		}

		// Resize the valid cover image, if necessary.
		if ($image->width() > $maxX || $image->height() > $maxY) {
			$image->resize(min($maxX, $maxY));
		}
		return $image;
	}
}
