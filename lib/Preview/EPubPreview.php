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

//.epub
use OCP\Preview\IProviderV2;
use OC\Archive\ZIP;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;

class EPubPreview implements IProviderV2 {
	private $zip;

	/**
	 * {@inheritDoc}
	 */
	public function getMimeType(): string {
		return '/application\/epub\+zip/';
	}

	/**
	 * Check if a preview can be generated for $path
	 *
	 * {@inheritDoc}
	 */
	public function isAvailable(FileInfo $file): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		$image = $this->extractThumbnail($file, '');
		if ($image && $image->valid()) {
			return $image;
		}
		return null;
	}

	/**
	 * extractThumbnail from complicated epub format
	 */
	private function extractThumbnail(File $file, string $path): ?IImage {
		$tmpManager = \OC::$server->get(\OCP\ITempManager::class);
		$sourceTmp = $tmpManager->getTemporaryFile();

		try {
			$content = $file->fopen('r');
			file_put_contents($sourceTmp, $content);

			$this->zip = new ZIP($sourceTmp);

			$img_data = null;
			$contentPath = $this->getContentPath();
			if ($contentPath){
				$package = $this->extractXML($contentPath);
				if ($package) {
					$path = $contentPath;
					$img_src = $cover = null;
					// Try first through <manifest>
					$items = $package->manifest->children();
					foreach( $items as $item) {
						if (($item['id'] == 'cover' || $item['id'] == 'cover-image') && preg_match('/image\//', (string) $item['media-type'])){
							$img_src = (string) $item['href'];
							break;
						}
					}

					// in references
					if (!$img_src) {
						$references = $package->guide->children();
						foreach( $references as $reference) {
							if ($reference['type'] == 'cover' || $reference['type'] == 'title-page') {
								$cover = (string) $reference['href'];
								break;
							}
						}
					}

					// no cover ? no image ? take the first page
					if (!$img_src && !$cover){
						$first_page_id = (string) $package->spine->itemref['idref'];
						if ($first_page_id) {
							foreach( $items as $item) {
								if ($item['id'] == $first_page_id) {
									$cover = (string) $item['href'];
									break;
								}
							}

						}
					}

					// have we a "cover" file ?
					if ($cover) {
						// relative to container
						$img_src = null;
						$path = $this->resolvePath( $path, $cover);
						$dom = $this->extractHTML( $path);
						if ($dom){
							// search img
							$images=$dom->getElementsByTagName('img');
							if ($images->length){
								$img_src = $images[0]->getAttribute('src');
							} else {
								$images=$dom->getElementsByTagName('image');
								if ($images->length){
									$img_src = $images[0]->getAttribute('xlink:href');
								}
							}
						}
					}// cover

					// img ?
					if ($img_src) {
						$img_src = $this->resolvePath( $path, $img_src);
						$img_data = $this->extractFileData($img_src);
					}
				}
			}

			// Pfff. Make a pause
			if ($img_data) {
				$image = new \OC_Image();
				$image->loadFromData($img_data);
				return $image;
			}
			return null;
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * find the main content XML (usually "content.opf")
	 */
	private function getContentPath() {
		$xml_container = $this->extractXML('META-INF/container.xml');
		if ($xml_container){
			$full_path = $xml_container->rootfiles->rootfile['full-path'][0];
			if ($full_path) {
				return $full_path->__toString();
			}
		}
		return null;
	}

	/**
	 * extract HTML from Zip path
	 */
	protected function extractHTML( $path) {
		$html = $this->extractFileData($path);
		if ($html) {
			$dom=new \DOMDocument('1.0','utf-8');
			$dom->strictErrorChecking=false;
			if (@$dom->loadHTML($html)) {
				return $dom;
			}
		}
		return null;
	}

	/**
	 * extract XML from Zip path
	 */
	private function extractXML( $path) {
		$xml = $this->extractFileData($path);
		if ($xml) {
			return simplexml_load_string($xml);
		}
		return null;
	}

	/**
	 * get unzipped data
	 * @param $path file path in zip
	 */
	private function extractFileData( $path) {
		$fp = $this->zip->getStream( $path, 'r');
		if ($fp) {
			$content = stream_get_contents($fp);
			fclose($fp);
			return $content;
		}
		return null;
	}

	/**
	 * Resolve relative $relPath from $path (removes ./, ../)
	 * @param $path reference path
	 * @param $relPath relative path
	 */
	private function resolvePath( $path, $relPath) {
		$path = dirname( $path).'/'.$relPath;
		$pieces = explode( '/', $path);
		$parents = array();
		foreach( $pieces as $dir) {
			switch( $dir) {
				case '.':
					// Don't need to do anything here
				break;
				case '..':
					array_pop( $parents);
					break;
				default:
					$parents[] = $dir;
				break;
			}
		}
		return implode('/', $parents);
	}
}
