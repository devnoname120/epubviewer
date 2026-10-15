<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Preview;

use ZipArchive;

final class CBZArchiveReader extends ComicArchiveReader {
	/** @return null|array{data: string, mime: string, path: string} */
	public function readCover(string $file): ?array {
		$this->assertArchiveFileAvailable($file, 'CBZ');
		ZipMetadataPreflight::check(
			$file,
			$this->limits->maxEntryCount,
			$this->limits->maxCentralDirectoryBytes,
			'CBZ',
		);

		$zip = new ZipArchive();
		$result = @$zip->open($file, ZipArchive::RDONLY | ZipArchive::CHECKCONS);
		if ($result !== true) {
			throw new \UnexpectedValueException('The CBZ is not a consistent ZIP archive.');
		}

		try {
			if ($zip->numFiles > $this->limits->maxEntryCount) {
				throw new \UnexpectedValueException('The CBZ exceeds the archive entry-count limit.');
			}

			/** @var null|array{index: int, path: string, size: int, packedSize: int, encryptionMethod: null|int} $best */
			$best = null;
			for ($index = 0; $index < $zip->numFiles; ++$index) {
				$stat = $zip->statIndex($index);
				if ($stat === false
					|| !isset($stat['name'], $stat['size'], $stat['comp_size'])
					|| !is_string($stat['name'])
					|| !is_int($stat['size'])
					|| !is_int($stat['comp_size'])) {
					throw new \UnexpectedValueException('The CBZ contains unreadable entry metadata.');
				}

				$path = $this->coverCandidatePath($stat['name']);
				if ($path === null || ($best !== null && !$this->candidateComesBefore($path, $best['path']))) {
					continue;
				}

				$encryptionMethod = null;
				if (array_key_exists('encryption_method', $stat)) {
					if (!is_int($stat['encryption_method'])) {
						throw new \UnexpectedValueException('The CBZ contains unreadable encryption metadata.');
					}
					$encryptionMethod = $stat['encryption_method'];
				}

				$best = [
					'index' => $index,
					'path' => $path,
					'size' => $stat['size'],
					'packedSize' => $stat['comp_size'],
					'encryptionMethod' => $encryptionMethod,
				];
			}

			if ($best === null) {
				return null;
			}

			if ($best['encryptionMethod'] !== null && $best['encryptionMethod'] !== ZipArchive::EM_NONE) {
				throw new \UnexpectedValueException('The selected CBZ cover is encrypted.');
			}
			$this->assertSelectedEntrySafe($best['size'], $best['packedSize'], 'selected CBZ cover');

			$stream = @$zip->getStreamIndex($best['index']);
			if ($stream === false) {
				throw new \UnexpectedValueException('The selected CBZ cover could not be opened.');
			}

			$data = $this->readSelectedEntry($stream, $best['size'], 'selected CBZ cover');
			return [
				'data' => $data,
				'mime' => $this->validatedCoverMime($data),
				'path' => $best['path'],
			];
		} finally {
			$zip->close();
		}
	}
}
