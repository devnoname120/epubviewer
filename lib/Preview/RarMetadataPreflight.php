<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Preview;

/** Rejects RAR archive modes that may open sibling volumes or require a password. */
final class RarMetadataPreflight {
	private const RAR4_SIGNATURE = "Rar!\x1a\x07\x00";
	private const RAR5_SIGNATURE = "Rar!\x1a\x07\x01\x00";
	private const PREFIX_BYTES = 128;

	public static function check(string $file): void {
		if (!is_file($file) || !is_readable($file)) {
			throw new \UnexpectedValueException('The CBR file is unavailable.');
		}

		$fileSize = filesize($file);
		if ($fileSize === false || $fileSize < strlen(self::RAR4_SIGNATURE)) {
			throw new \UnexpectedValueException('The CBR RAR signature is truncated.');
		}

		$stream = fopen($file, 'rb');
		if ($stream === false) {
			throw new \UnexpectedValueException('The CBR file is unavailable.');
		}
		try {
			$data = fread($stream, min($fileSize, self::PREFIX_BYTES));
		} finally {
			fclose($stream);
		}
		if ($data === false) {
			throw new \UnexpectedValueException('The CBR RAR header could not be read.');
		}

		if (str_starts_with($data, self::RAR4_SIGNATURE)) {
			self::checkRar4($data, $fileSize);
			return;
		}
		if (str_starts_with($data, self::RAR5_SIGNATURE)) {
			self::checkRar5($data, $fileSize);
			return;
		}

		throw new \UnexpectedValueException('The CBR has an invalid or unsupported RAR signature.');
	}

	private static function checkRar4(string $data, int $fileSize): void {
		$signatureBytes = strlen(self::RAR4_SIGNATURE);
		$minimumMainHeaderBytes = 13;
		if (strlen($data) < $signatureBytes + $minimumMainHeaderBytes) {
			throw new \UnexpectedValueException('The CBR RAR4 main header is truncated.');
		}

		$type = ord($data[$signatureBytes + 2]);
		$flags = self::u16($data, $signatureBytes + 3);
		$headerBytes = self::u16($data, $signatureBytes + 5);
		if ($type !== 0x73) {
			throw new \UnexpectedValueException('The CBR RAR4 main header is missing.');
		}
		if ($headerBytes < $minimumMainHeaderBytes || $headerBytes > $fileSize - $signatureBytes) {
			throw new \UnexpectedValueException('The CBR RAR4 main header is truncated or invalid.');
		}
		if (($flags & 0x0001) !== 0) {
			throw new \UnexpectedValueException('The CBR is a multi-volume archive, which is not supported.');
		}
		if (($flags & 0x0080) !== 0) {
			throw new \UnexpectedValueException('The CBR is header-encrypted, which is not supported.');
		}
	}

	private static function checkRar5(string $data, int $fileSize): void {
		$offset = strlen(self::RAR5_SIGNATURE) + 4;
		if ($offset >= strlen($data)) {
			throw new \UnexpectedValueException('The CBR RAR5 main header is truncated.');
		}

		$headerBytes = self::readVInt($data, $offset, strlen($data), 3);
		$blockStart = $offset;
		if ($headerBytes < 2 || $headerBytes > $fileSize - $blockStart) {
			throw new \UnexpectedValueException('The CBR RAR5 main header is truncated or invalid.');
		}

		$blockEnd = $blockStart + $headerBytes;
		$availableEnd = min($blockEnd, strlen($data));
		$headerType = self::readVInt($data, $offset, $availableEnd);
		$headerFlags = self::readVInt($data, $offset, $availableEnd);

		$extraBytes = 0;
		if (($headerFlags & 0x0001) !== 0) {
			$extraBytes = self::readVInt($data, $offset, $availableEnd);
		}

		$dataBytes = 0;
		if (($headerFlags & 0x0002) !== 0) {
			$dataBytes = self::readVInt($data, $offset, $availableEnd);
		}
		if ($dataBytes > $fileSize - $blockEnd) {
			throw new \UnexpectedValueException('The CBR RAR5 header data is truncated.');
		}

		if ($headerType === 4) {
			throw new \UnexpectedValueException('The CBR is header-encrypted, which is not supported.');
		}
		if ($headerType !== 1) {
			throw new \UnexpectedValueException('The CBR RAR5 main header is missing.');
		}

		$archiveFlags = self::readVInt($data, $offset, $availableEnd);
		if (($archiveFlags & 0x0003) !== 0) {
			throw new \UnexpectedValueException('The CBR is a multi-volume archive, which is not supported.');
		}
		if ($extraBytes > $blockEnd - $offset) {
			throw new \UnexpectedValueException('The CBR RAR5 extra header data is truncated.');
		}
	}

	private static function readVInt(
		string $data,
		int &$offset,
		int $end,
		int $maxBytes = 8,
	): int {
		$value = 0;
		for ($index = 0; $index < $maxBytes; ++$index) {
			if ($offset >= $end || $offset >= strlen($data)) {
				throw new \UnexpectedValueException('The CBR RAR variable integer is truncated.');
			}

			$byte = ord($data[$offset++]);
			$value |= ($byte & 0x7f) << ($index * 7);
			if (($byte & 0x80) === 0) {
				return $value;
			}
		}

		throw new \UnexpectedValueException('The CBR RAR variable integer exceeds its supported size.');
	}

	private static function u16(string $data, int $offset): int {
		return ord($data[$offset]) | (ord($data[$offset + 1]) << 8);
	}
}
