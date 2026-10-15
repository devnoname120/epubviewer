<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Preview;

/** Checks central-directory cardinality and size before libzip allocates it. */
final class ZipMetadataPreflight {
	public static function check(
		string $file,
		int $maxEntryCount,
		int $maxCentralDirectoryBytes,
		string $archiveDescription,
	): void {
		$stream = fopen($file, 'rb');
		if ($stream === false) {
			throw new \UnexpectedValueException(sprintf('The %s file is unavailable.', $archiveDescription));
		}
		try {
			$size = filesize($file);
			if ($size === false || $size < 22) {
				throw new \UnexpectedValueException(sprintf(
					'The %s is not a consistent ZIP archive: end record missing.',
					$archiveDescription,
				));
			}
			$tailOffset = max(0, $size - 65557);
			$tail = self::read($stream, $tailOffset, $size - $tailOffset, $archiveDescription);
			$end = null;
			for ($offset = strlen($tail) - 22; $offset >= 0; --$offset) {
				if (substr($tail, $offset, 4) === "PK\x05\x06"
					&& $offset + 22 + self::u16($tail, $offset + 20) === strlen($tail)) {
					$end = $offset;
					break;
				}
			}
			if ($end === null) {
				throw new \UnexpectedValueException(sprintf('The %s ZIP end record is invalid.', $archiveDescription));
			}
			$endOffset = $tailOffset + $end;
			$count = self::u16($tail, $end + 10);
			$directoryBytes = self::u32($tail, $end + 12);
			$directoryOffset = self::u32($tail, $end + 16);
			if (self::u16($tail, $end + 4) !== 0 || self::u16($tail, $end + 6) !== 0
				|| self::u16($tail, $end + 8) !== $count) {
				throw new \UnexpectedValueException(sprintf(
					'Multi-disk %s ZIP archives are not supported.',
					$archiveDescription,
				));
			}
			if ($count === 65535 || $directoryBytes === 0xffffffff || $directoryOffset === 0xffffffff) {
				$locator = self::read($stream, $endOffset - 20, 20, $archiveDescription);
				if (substr($locator, 0, 4) !== "PK\x06\x07" || self::u32($locator, 4) !== 0 || self::u32($locator, 16) !== 1) {
					throw new \UnexpectedValueException(sprintf('The %s ZIP64 locator is invalid.', $archiveDescription));
				}
				$zip64Offset = self::u64($locator, 8, $archiveDescription);
				$record = self::read($stream, $zip64Offset, 56, $archiveDescription);
				if (substr($record, 0, 4) !== "PK\x06\x06" || self::u64($record, 4, $archiveDescription) < 44
					|| $zip64Offset + 12 + self::u64($record, 4, $archiveDescription) !== $endOffset - 20
					|| self::u32($record, 16) !== 0 || self::u32($record, 20) !== 0
					|| self::u64($record, 24, $archiveDescription) !== self::u64($record, 32, $archiveDescription)) {
					throw new \UnexpectedValueException(sprintf('The %s ZIP64 end record is invalid.', $archiveDescription));
				}
				$count = self::u64($record, 32, $archiveDescription);
				$directoryBytes = self::u64($record, 40, $archiveDescription);
				$directoryOffset = self::u64($record, 48, $archiveDescription);
				$endOffset = $zip64Offset;
			}
			if ($count > $maxEntryCount) {
				throw new \UnexpectedValueException(sprintf(
					'The %s contains more than %d archive entries before ZIP opening.',
					$archiveDescription,
					$maxEntryCount,
				));
			}
			if ($directoryBytes > $maxCentralDirectoryBytes || $directoryOffset + $directoryBytes !== $endOffset) {
				throw new \UnexpectedValueException(sprintf(
					'The %s central directory is oversized or inconsistent.',
					$archiveDescription,
				));
			}
			$offset = $directoryOffset;
			$seen = 0;
			while ($offset < $endOffset) {
				if (++$seen > $maxEntryCount || $offset + 46 > $endOffset) {
					throw new \UnexpectedValueException(sprintf(
						'The %s central directory exceeds the entry-count limit or is truncated.',
						$archiveDescription,
					));
				}
				$header = self::read($stream, $offset, 46, $archiveDescription);
				if (substr($header, 0, 4) !== "PK\x01\x02" || self::u16($header, 34) !== 0) {
					throw new \UnexpectedValueException(sprintf(
						'The %s central directory is invalid.',
						$archiveDescription,
					));
				}
				$offset += 46 + self::u16($header, 28) + self::u16($header, 30) + self::u16($header, 32);
			}
			if ($offset !== $endOffset || $seen !== $count) {
				throw new \UnexpectedValueException(sprintf(
					'The %s central directory entry count is inconsistent.',
					$archiveDescription,
				));
			}
		} finally {
			fclose($stream);
		}
	}

	/** @param resource $stream */
	private static function read($stream, int $offset, int $length, string $archiveDescription): string {
		if ($offset < 0 || fseek($stream, $offset) !== 0) {
			throw new \UnexpectedValueException(sprintf(
				'The %s ZIP metadata offset is invalid.',
				$archiveDescription,
			));
		}
		$data = fread($stream, $length);
		if ($data === false || strlen($data) !== $length) {
			throw new \UnexpectedValueException(sprintf(
				'The %s ZIP metadata is truncated.',
				$archiveDescription,
			));
		}
		return $data;
	}

	private static function u16(string $data, int $offset): int {
		return ord($data[$offset]) | (ord($data[$offset + 1]) << 8);
	}

	private static function u32(string $data, int $offset): int {
		return self::u16($data, $offset) | (self::u16($data, $offset + 2) << 16);
	}

	private static function u64(string $data, int $offset, string $archiveDescription): int {
		$low = self::u32($data, $offset);
		$high = self::u32($data, $offset + 4);
		if (PHP_INT_SIZE < 8) {
			if ($high !== 0 || $low > PHP_INT_MAX) {
				throw new \UnexpectedValueException(sprintf(
					'The %s ZIP64 metadata exceeds the platform offset range.',
					$archiveDescription,
				));
			}

			return $low;
		}
		if ($high > 0x7fffffff) {
			throw new \UnexpectedValueException(sprintf(
				'The %s ZIP64 metadata exceeds the platform offset range.',
				$archiveDescription,
			));
		}

		return $high * 0x100000000 + $low;
	}
}
