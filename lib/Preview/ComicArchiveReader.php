<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Preview;

abstract class ComicArchiveReader {
	protected readonly ComicArchiveLimits $limits;

	public function __construct(?ComicArchiveLimits $limits = null) {
		$this->limits = $limits ?? new ComicArchiveLimits();
	}

	/** @return null|array{data: string, mime: string, path: string} */
	abstract public function readCover(string $file): ?array;

	public function getMaxTemporaryArchiveBytes(): int {
		return $this->limits->maxTemporaryArchiveBytes;
	}

	protected function assertArchiveFileAvailable(string $file, string $description): void {
		if (!is_file($file) || !is_readable($file)) {
			throw new \UnexpectedValueException(sprintf('The %s file is unavailable.', $description));
		}

		$size = filesize($file);
		if ($size === false || $size < 1) {
			throw new \UnexpectedValueException(sprintf('The %s file is empty or has an unknown size.', $description));
		}
	}

	protected function coverCandidatePath(string $name): ?string {
		if ($name === '' || strlen($name) > $this->limits->maxEntryNameBytes || str_contains($name, "\0")) {
			return null;
		}

		$path = str_replace('\\', '/', $name);
		if (str_starts_with($path, '/') || preg_match('/^[a-z]:\//i', $path) === 1) {
			return null;
		}

		$parts = explode('/', $path);
		if (count($parts) > $this->limits->maxPathDepth) {
			return null;
		}

		foreach ($parts as $part) {
			if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.')) {
				return null;
			}
			if (strcasecmp($part, '__MACOSX') === 0) {
				return null;
			}
		}

		$extension = strtolower(pathinfo($parts[array_key_last($parts)], PATHINFO_EXTENSION));
		if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
			return null;
		}

		return implode('/', $parts);
	}

	protected function candidateComesBefore(string $candidate, string $current): bool {
		$natural = strnatcasecmp($candidate, $current);
		return $natural < 0 || ($natural === 0 && strcmp($candidate, $current) < 0);
	}

	protected function assertSelectedEntrySafe(int $unpackedSize, int $packedSize, string $description): void {
		if ($unpackedSize < 1 || $packedSize < 1) {
			throw new \UnexpectedValueException(sprintf('The %s has invalid archive sizes.', $description));
		}
		if ($unpackedSize > $this->limits->maxCoverBytes) {
			throw new \UnexpectedValueException(sprintf('The %s exceeds the encoded cover limit.', $description));
		}
		if ($unpackedSize >= $this->limits->minCompressionRatioBytes
			&& $unpackedSize / $packedSize > $this->limits->maxCompressionRatio) {
			throw new \UnexpectedValueException(sprintf('The %s exceeds the compression-ratio limit.', $description));
		}
	}

	/** @param resource $stream */
	protected function readSelectedEntry($stream, int $declaredSize, string $description): string {
		try {
			$data = stream_get_contents($stream, $this->limits->maxCoverBytes + 1);
		} finally {
			fclose($stream);
		}

		if ($data === false) {
			throw new \UnexpectedValueException(sprintf('The %s could not be read.', $description));
		}
		if (strlen($data) > $this->limits->maxCoverBytes) {
			throw new \UnexpectedValueException(sprintf('The %s exceeds the encoded cover limit.', $description));
		}
		if (strlen($data) !== $declaredSize) {
			throw new \UnexpectedValueException(sprintf('The %s does not match its declared size.', $description));
		}

		return $data;
	}

	protected function validatedCoverMime(string $data): string {
		return ImageHeaderPreflight::check(
			$data,
			$this->limits->maxCoverBytes,
			$this->limits->maxImageDimension,
			$this->limits->maxImagePixels,
		);
	}
}
