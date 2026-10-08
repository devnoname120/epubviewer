<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Preview;

/** Checks central-directory cardinality and size before libzip allocates it. */
final class ZipMetadataPreflight {
	public static function check(string $file, EPubArchiveLimits $limits): void {
		$stream = fopen($file, 'rb');
		if ($stream === false) {
			throw new \UnexpectedValueException('The EPUB file is unavailable.');
		}
		try {
			$size = filesize($file);
			if ($size === false || $size < 22) {
				throw new \UnexpectedValueException('The EPUB is not a consistent ZIP archive: end record missing.');
			}
			$tailOffset = max(0, $size - 65557);
			$tail = self::read($stream, $tailOffset, $size - $tailOffset);
			$end = null;
			for ($offset = strlen($tail) - 22; $offset >= 0; --$offset) {
				if (substr($tail, $offset, 4) === "PK\x05\x06"
					&& $offset + 22 + self::u16($tail, $offset + 20) === strlen($tail)) {
					$end = $offset;
					break;
				}
			}
			if ($end === null) {
				throw new \UnexpectedValueException('The EPUB ZIP end record is invalid.');
			}
			$endOffset = $tailOffset + $end;
			$count = self::u16($tail, $end + 10);
			$directoryBytes = self::u32($tail, $end + 12);
			$directoryOffset = self::u32($tail, $end + 16);
			if (self::u16($tail, $end + 4) !== 0 || self::u16($tail, $end + 6) !== 0
				|| self::u16($tail, $end + 8) !== $count) {
				throw new \UnexpectedValueException('Multi-disk EPUB ZIP archives are not supported.');
			}
			if ($count === 65535 || $directoryBytes === 0xffffffff || $directoryOffset === 0xffffffff) {
				$locator = self::read($stream, $endOffset - 20, 20);
				if (substr($locator, 0, 4) !== "PK\x06\x07" || self::u32($locator, 4) !== 0 || self::u32($locator, 16) !== 1) {
					throw new \UnexpectedValueException('The EPUB ZIP64 locator is invalid.');
				}
				$zip64Offset = self::u64($locator, 8);
				$record = self::read($stream, $zip64Offset, 56);
				if (substr($record, 0, 4) !== "PK\x06\x06" || self::u64($record, 4) < 44
					|| $zip64Offset + 12 + self::u64($record, 4) !== $endOffset - 20
					|| self::u32($record, 16) !== 0 || self::u32($record, 20) !== 0
					|| self::u64($record, 24) !== self::u64($record, 32)) {
					throw new \UnexpectedValueException('The EPUB ZIP64 end record is invalid.');
				}
				$count = self::u64($record, 32);
				$directoryBytes = self::u64($record, 40);
				$directoryOffset = self::u64($record, 48);
				$endOffset = $zip64Offset;
			}
			if ($count > $limits->maxEntryCount) {
				throw new \UnexpectedValueException(sprintf('The EPUB contains more than %d archive entries before ZIP opening.', $limits->maxEntryCount));
			}
			if ($directoryBytes > $limits->maxCentralDirectoryBytes || $directoryOffset + $directoryBytes !== $endOffset) {
				throw new \UnexpectedValueException('The EPUB central directory is oversized or inconsistent.');
			}
			$offset = $directoryOffset;
			$seen = 0;
			while ($offset < $endOffset) {
				if (++$seen > $limits->maxEntryCount || $offset + 46 > $endOffset) {
					throw new \UnexpectedValueException('The EPUB central directory exceeds the entry-count limit or is truncated.');
				}
				$header = self::read($stream, $offset, 46);
				if (substr($header, 0, 4) !== "PK\x01\x02" || self::u16($header, 34) !== 0) {
					throw new \UnexpectedValueException('The EPUB central directory is invalid.');
				}
				$offset += 46 + self::u16($header, 28) + self::u16($header, 30) + self::u16($header, 32);
			}
			if ($offset !== $endOffset || $seen !== $count) {
				throw new \UnexpectedValueException('The EPUB central directory entry count is inconsistent.');
			}
		} finally {
			fclose($stream);
		}
	}

	/** @param resource $stream */
	private static function read($stream, int $offset, int $length): string {
		if ($offset < 0 || fseek($stream, $offset) !== 0) {
			throw new \UnexpectedValueException('The EPUB ZIP metadata offset is invalid.');
		}
		$data = fread($stream, $length);
		if ($data === false || strlen($data) !== $length) {
			throw new \UnexpectedValueException('The EPUB ZIP metadata is truncated.');
		}
		return $data;
	}

	private static function u16(string $data, int $offset): int {
		return ord($data[$offset]) | (ord($data[$offset + 1]) << 8);
	}

	private static function u32(string $data, int $offset): int {
		return self::u16($data, $offset) | (self::u16($data, $offset + 2) << 16);
	}

	private static function u64(string $data, int $offset): int {
		// Accepted archives and cardinalities are below 4 GiB; reject larger ZIP64 values.
		if (self::u32($data, $offset + 4) !== 0) {
			throw new \UnexpectedValueException('The EPUB ZIP64 metadata exceeds the preview limits.');
		}
		return self::u32($data, $offset);
	}
}
