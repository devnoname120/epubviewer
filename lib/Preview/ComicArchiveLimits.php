<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Preview;

/** Resource limits applied while locating a comic archive cover image. */
final class ComicArchiveLimits {
	public const DEFAULT_MAX_TEMPORARY_ARCHIVE_BYTES = 1024 * 1024 * 1024;
	public const DEFAULT_MAX_ENTRY_COUNT = 10000;
	public const DEFAULT_MAX_CENTRAL_DIRECTORY_BYTES = 16 * 1024 * 1024;
	public const DEFAULT_MAX_ENTRY_NAME_BYTES = 1024;
	public const DEFAULT_MAX_PATH_DEPTH = 16;
	public const DEFAULT_MAX_COVER_BYTES = 32 * 1024 * 1024;
	public const DEFAULT_MAX_COMPRESSION_RATIO = 200;
	public const DEFAULT_MIN_COMPRESSION_RATIO_BYTES = 1024 * 1024;
	public const DEFAULT_MAX_SOLID_PREFIX_BYTES = 1024 * 1024 * 1024;
	public const DEFAULT_MAX_IMAGE_DIMENSION = 8192;
	public const DEFAULT_MAX_IMAGE_PIXELS = 16 * 1024 * 1024;

	public function __construct(
		public readonly int $maxTemporaryArchiveBytes = self::DEFAULT_MAX_TEMPORARY_ARCHIVE_BYTES,
		public readonly int $maxEntryCount = self::DEFAULT_MAX_ENTRY_COUNT,
		public readonly int $maxCentralDirectoryBytes = self::DEFAULT_MAX_CENTRAL_DIRECTORY_BYTES,
		public readonly int $maxEntryNameBytes = self::DEFAULT_MAX_ENTRY_NAME_BYTES,
		public readonly int $maxPathDepth = self::DEFAULT_MAX_PATH_DEPTH,
		public readonly int $maxCoverBytes = self::DEFAULT_MAX_COVER_BYTES,
		public readonly int $maxCompressionRatio = self::DEFAULT_MAX_COMPRESSION_RATIO,
		public readonly int $minCompressionRatioBytes = self::DEFAULT_MIN_COMPRESSION_RATIO_BYTES,
		public readonly int $maxSolidPrefixBytes = self::DEFAULT_MAX_SOLID_PREFIX_BYTES,
		public readonly int $maxImageDimension = self::DEFAULT_MAX_IMAGE_DIMENSION,
		public readonly int $maxImagePixels = self::DEFAULT_MAX_IMAGE_PIXELS,
	) {
		foreach (get_object_vars($this) as $name => $value) {
			if ($value < 1) {
				throw new \InvalidArgumentException(sprintf('%s must be greater than zero.', $name));
			}
		}

		if ($this->maxTemporaryArchiveBytes === PHP_INT_MAX || $this->maxCoverBytes === PHP_INT_MAX) {
			throw new \InvalidArgumentException('Limits used for bounded reads must leave room for a sentinel byte.');
		}

		if ($this->maxSolidPrefixBytes < $this->maxCoverBytes) {
			throw new \InvalidArgumentException('The solid archive prefix limit must be at least the cover limit.');
		}
	}
}
