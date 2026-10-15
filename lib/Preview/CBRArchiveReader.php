<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Preview;

final class CBRArchiveReader extends ComicArchiveReader {
	/** @return null|array{data: string, mime: string, path: string} */
	public function readCover(string $file): ?array {
		if (!class_exists(\RarArchive::class, false)) {
			throw new \UnexpectedValueException('CBR previews require the PHP RAR extension.');
		}

		$this->assertArchiveFileAvailable($file, 'CBR');
		RarMetadataPreflight::check($file);
		$archive = @\RarArchive::open($file);
		if (!$archive instanceof \RarArchive) {
			throw new \UnexpectedValueException('The CBR is not a consistent RAR archive.');
		}

		try {
			if ($archive->isBroken()) {
				throw new \UnexpectedValueException('The CBR is not a consistent RAR archive.');
			}

			$isSolid = $archive->isSolid();
			$count = 0;
			$prefixBytes = 0;
			$prefixLimitExceeded = false;
			/** @var null|array{entry: \RarEntry, path: string, unpackedSize: int, packedSize: int, prefixLimitExceeded: bool} $best */
			$best = null;

			foreach ($archive as $entry) {
				if (++$count > $this->limits->maxEntryCount) {
					throw new \UnexpectedValueException('The CBR exceeds the archive entry-count limit.');
				}
				if (!$entry instanceof \RarEntry) {
					throw new \UnexpectedValueException('The CBR contains unreadable entry metadata.');
				}

				$name = $entry->getName();
				$unpackedSize = $entry->getUnpackedSize();
				$packedSize = $entry->getPackedSize();
				if ($unpackedSize < 0 || $packedSize < 0) {
					throw new \UnexpectedValueException('The CBR contains invalid entry sizes.');
				}

				if ($isSolid && !$prefixLimitExceeded) {
					if ($unpackedSize > $this->limits->maxSolidPrefixBytes - $prefixBytes) {
						$prefixLimitExceeded = true;
					} else {
						$prefixBytes += $unpackedSize;
					}
				}

				if ($entry->isDirectory()) {
					continue;
				}
				$path = $this->coverCandidatePath($name);
				if ($path === null || ($best !== null && !$this->candidateComesBefore($path, $best['path']))) {
					continue;
				}

				$best = [
					'entry' => $entry,
					'path' => $path,
					'unpackedSize' => $unpackedSize,
					'packedSize' => $packedSize,
					'prefixLimitExceeded' => $prefixLimitExceeded,
				];
			}

			if ($best === null) {
				return null;
			}
			if ($best['entry']->isEncrypted()) {
				throw new \UnexpectedValueException('The selected CBR cover is encrypted.');
			}
			if ($best['entry']->getRedirType() !== null) {
				throw new \UnexpectedValueException('The selected CBR cover is an archive redirection.');
			}
			if ($best['prefixLimitExceeded']) {
				throw new \UnexpectedValueException('The selected CBR cover exceeds the solid archive prefix limit.');
			}
			$this->assertSelectedEntrySafe($best['unpackedSize'], $best['packedSize'], 'selected CBR cover');

			$stream = @$best['entry']->getStream();
			if ($stream === false) {
				throw new \UnexpectedValueException('The selected CBR cover could not be opened.');
			}

			$data = $this->readSelectedEntry($stream, $best['unpackedSize'], 'selected CBR cover');
			return [
				'data' => $data,
				'mime' => $this->validatedCoverMime($data),
				'path' => $best['path'],
			];
		} finally {
			@$archive->close();
		}
	}
}
