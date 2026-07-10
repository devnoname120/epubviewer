<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Preview;

/**
 * Resource limits applied while locating an EPUB cover image.
 *
 * These defaults bound the compressed archive metadata and the container, OPF,
 * and cover entries that preview generation actually inflates. Unrelated book
 * resources are inspected by name but are never decompressed by this reader.
 */
final class EPubArchiveLimits {
	/** Maximum compressed EPUB size accepted by the preview provider (100 MiB). */
	public const DEFAULT_MAX_ARCHIVE_BYTES = 100 * 1024 * 1024;

	/** Maximum number of central-directory entries accepted in one EPUB. */
	public const DEFAULT_MAX_ENTRY_COUNT = 10000;

	/** Maximum sum of the container, package, and cover bytes read (512 MiB). */
	public const DEFAULT_MAX_TOTAL_UNCOMPRESSED_BYTES = 512 * 1024 * 1024;

	/** Maximum declared uncompressed size of any entry the reader opens (64 MiB). */
	public const DEFAULT_MAX_ENTRY_UNCOMPRESSED_BYTES = 64 * 1024 * 1024;

	/** Maximum compression ratio for entries the reader opens. */
	public const DEFAULT_MAX_COMPRESSION_RATIO = 200;

	/** Ignore ratio checks below 1 MiB to avoid penalizing small repetitive XML. */
	public const DEFAULT_MIN_COMPRESSION_RATIO_BYTES = 1024 * 1024;

	/** Maximum uncompressed size of META-INF/container.xml (256 KiB). */
	public const DEFAULT_MAX_CONTAINER_XML_BYTES = 256 * 1024;

	/** Maximum uncompressed size of the package document (4 MiB). */
	public const DEFAULT_MAX_PACKAGE_DOCUMENT_BYTES = 4 * 1024 * 1024;

	/** Maximum uncompressed size of the cover image passed to OCP\Image (32 MiB). */
	public const DEFAULT_MAX_COVER_BYTES = 32 * 1024 * 1024;

	public function __construct(
		public readonly int $maxArchiveBytes = self::DEFAULT_MAX_ARCHIVE_BYTES,
		public readonly int $maxEntryCount = self::DEFAULT_MAX_ENTRY_COUNT,
		public readonly int $maxTotalUncompressedBytes = self::DEFAULT_MAX_TOTAL_UNCOMPRESSED_BYTES,
		public readonly int $maxEntryUncompressedBytes = self::DEFAULT_MAX_ENTRY_UNCOMPRESSED_BYTES,
		public readonly int $maxCompressionRatio = self::DEFAULT_MAX_COMPRESSION_RATIO,
		public readonly int $minCompressionRatioBytes = self::DEFAULT_MIN_COMPRESSION_RATIO_BYTES,
		public readonly int $maxContainerXmlBytes = self::DEFAULT_MAX_CONTAINER_XML_BYTES,
		public readonly int $maxPackageDocumentBytes = self::DEFAULT_MAX_PACKAGE_DOCUMENT_BYTES,
		public readonly int $maxCoverBytes = self::DEFAULT_MAX_COVER_BYTES,
	) {
		foreach (get_object_vars($this) as $name => $value) {
			if ($value < 1) {
				throw new \InvalidArgumentException(sprintf('%s must be greater than zero.', $name));
			}
		}

		if ($this->maxTotalUncompressedBytes < $this->maxEntryUncompressedBytes) {
			throw new \InvalidArgumentException('The total uncompressed size limit must be at least the per-entry limit.');
		}

		if ($this->maxContainerXmlBytes > $this->maxEntryUncompressedBytes
			|| $this->maxPackageDocumentBytes > $this->maxEntryUncompressedBytes
			|| $this->maxCoverBytes > $this->maxEntryUncompressedBytes) {
			throw new \InvalidArgumentException('Specialized EPUB entry limits must not exceed the general per-entry limit.');
		}
	}
}
