<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Preview;

/** Header-only checks; no raster allocation is permitted before this succeeds. */
final class ImageHeaderPreflight {
	public static function check(
		string $data,
		int $maxBytes,
		int $maxDimension,
		int $maxPixels,
	): string {
		if (strlen($data) > $maxBytes) {
			throw new \UnexpectedValueException('The cover exceeds the encoded image limit.');
		}
		$info = @getimagesizefromstring($data);
		if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
			throw new \UnexpectedValueException('The cover has unknown or invalid image dimensions.');
		}
		[$width, $height, $type] = $info;
		if ($width < 1 || $height < 1 || $width > $maxDimension
			|| $height > $maxDimension || $width * $height > $maxPixels) {
			throw new \UnexpectedValueException('The cover exceeds the decoded image pixel limit.');
		}
		// Animated covers are refused so the decoder cannot allocate additional frames.
		if ($type === IMAGETYPE_GIF) {
			self::checkGif($data, $width, $height);
		} elseif ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
			self::checkChunks($data, $type === IMAGETYPE_PNG, $width, $height);
		}

		return image_type_to_mime_type($type);
	}

	private static function checkChunks(string $data, bool $png, int $width, int $height): void {
		$offset = $png ? 8 : 12;
		$end = strlen($data);
		$frames = 0;
		while ($offset < $end) {
			if ($offset + 8 > $end) {
				throw new \UnexpectedValueException('The cover has a truncated image header.');
			}
			$chunkLength = unpack($png ? 'N' : 'V', substr($data, $offset + ($png ? 0 : 4), 4));
			if ($chunkLength === false) {
				throw new \UnexpectedValueException('The cover has a truncated image chunk length.');
			}
			$length = $chunkLength[1];
			$tag = substr($data, $offset + ($png ? 4 : 0), 4);
			if ($offset + 8 + $length + ($png ? 4 : $length % 2) > $end) {
				throw new \UnexpectedValueException('The cover has an invalid image chunk length.');
			}
			if ($png && (($offset === 8 && ($tag !== 'IHDR' || $length !== 13)) || ($offset !== 8 && $tag === 'IHDR'))) {
				throw new \UnexpectedValueException('The PNG cover has an invalid dimension header.');
			}
			if (!$png && ($tag === 'VP8 ' || $tag === 'VP8L')) {
				if (++$frames > 1) {
					throw new \UnexpectedValueException('Multiple WebP cover frames are not supported.');
				}
				if ($tag === 'VP8L' && $length >= 5 && ord($data[$offset + 8]) === 0x2f) {
					$frameBits = unpack('V', substr($data, $offset + 9, 4));
					if ($frameBits === false) {
						throw new \UnexpectedValueException('The WebP cover has a truncated frame header.');
					}
					$bits = $frameBits[1];
					$frameWidth = 1 + ($bits & 0x3fff);
					$frameHeight = 1 + (($bits >> 14) & 0x3fff);
				} elseif ($tag === 'VP8 ' && $length >= 10 && substr($data, $offset + 11, 3) === "\x9d\x01\x2a") {
					$dimensions = unpack('vwidth/vheight', substr($data, $offset + 14, 4));
					if ($dimensions === false) {
						throw new \UnexpectedValueException('The WebP cover has a truncated frame dimensions header.');
					}
					$frameWidth = $dimensions['width'] & 0x3fff;
					$frameHeight = $dimensions['height'] & 0x3fff;
				} else {
					throw new \UnexpectedValueException('The WebP cover has an invalid frame header.');
				}
				if ($frameWidth !== $width || $frameHeight !== $height) {
					throw new \UnexpectedValueException('The WebP frame differs from its validated dimensions.');
				}
			}
			if (in_array($tag, ['acTL', 'ANIM', 'ANMF'], true)
				|| (!$png && $tag === 'VP8X' && $length >= 1 && (ord($data[$offset + 8]) & 2) !== 0)) {
				throw new \UnexpectedValueException('Animated cover images are not supported.');
			}
			$offset += 8 + $length + ($png ? 4 : $length % 2);
			if ($offset > $end) {
				throw new \UnexpectedValueException('The cover has an invalid image chunk length.');
			}
		}
	}

	private static function checkGif(string $data, int $width, int $height): void {
		$end = strlen($data);
		if ($end < 13) {
			throw new \UnexpectedValueException('The GIF cover header is truncated.');
		}
		$flags = ord($data[10]);
		$offset = 13 + (($flags & 128) !== 0 ? 3 * (1 << (($flags & 7) + 1)) : 0);
		$frames = 0;
		while ($offset < $end) {
			$tag = ord($data[$offset++]);
			if ($tag === 0x3b && $frames === 1) {
				return;
			}
			if ($tag === 0x2c) {
				if (++$frames > 1) {
					throw new \UnexpectedValueException('Animated cover images are not supported.');
				}
				if ($offset + 9 >= $end) {
					break;
				}
				$frame = unpack('vx/vy/vwidth/vheight', substr($data, $offset, 8));
				if ($frame === false) {
					throw new \UnexpectedValueException('The GIF cover has a truncated frame header.');
				}
				if ($frame['width'] < 1 || $frame['height'] < 1
					|| $width < $frame['x'] + $frame['width'] || $height < $frame['y'] + $frame['height']) {
					throw new \UnexpectedValueException('The GIF frame exceeds the validated image dimensions.');
				}
				$flags = ord($data[$offset + 8]);
				$offset += 9 + (($flags & 128) !== 0 ? 3 * (1 << (($flags & 7) + 1)) : 0) + 1;
			} elseif ($tag === 0x21) {
				++$offset; // Extension label; its content is a sequence of bounded sub-blocks.
			} else {
				break;
			}
			while ($offset < $end) {
				$length = ord($data[$offset++]);
				if ($length === 0) {
					break;
				}
				$offset += $length;
			}
		}
		throw new \UnexpectedValueException('The GIF cover is malformed.');
	}
}
