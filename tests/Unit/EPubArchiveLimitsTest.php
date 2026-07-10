<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Preview\EPubArchiveLimits;
use PHPUnit\Framework\TestCase;

class EPubArchiveLimitsTest extends TestCase {
	public function testDefaultsMatchDocumentedConstants(): void {
		$limits = new EPubArchiveLimits();

		self::assertSame(EPubArchiveLimits::DEFAULT_MAX_ARCHIVE_BYTES, $limits->maxArchiveBytes);
		self::assertSame(EPubArchiveLimits::DEFAULT_MAX_ENTRY_COUNT, $limits->maxEntryCount);
		self::assertSame(EPubArchiveLimits::DEFAULT_MAX_TOTAL_UNCOMPRESSED_BYTES, $limits->maxTotalUncompressedBytes);
		self::assertSame(EPubArchiveLimits::DEFAULT_MAX_ENTRY_UNCOMPRESSED_BYTES, $limits->maxEntryUncompressedBytes);
		self::assertSame(EPubArchiveLimits::DEFAULT_MAX_COMPRESSION_RATIO, $limits->maxCompressionRatio);
		self::assertSame(EPubArchiveLimits::DEFAULT_MIN_COMPRESSION_RATIO_BYTES, $limits->minCompressionRatioBytes);
		self::assertSame(EPubArchiveLimits::DEFAULT_MAX_CONTAINER_XML_BYTES, $limits->maxContainerXmlBytes);
		self::assertSame(EPubArchiveLimits::DEFAULT_MAX_PACKAGE_DOCUMENT_BYTES, $limits->maxPackageDocumentBytes);
		self::assertSame(EPubArchiveLimits::DEFAULT_MAX_COVER_BYTES, $limits->maxCoverBytes);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function positiveLimitProvider(): array {
		return [
			'maximum archive bytes' => ['maxArchiveBytes'],
			'maximum entry count' => ['maxEntryCount'],
			'maximum total uncompressed bytes' => ['maxTotalUncompressedBytes'],
			'maximum entry uncompressed bytes' => ['maxEntryUncompressedBytes'],
			'maximum compression ratio' => ['maxCompressionRatio'],
			'minimum compression-ratio bytes' => ['minCompressionRatioBytes'],
			'maximum container XML bytes' => ['maxContainerXmlBytes'],
			'maximum package document bytes' => ['maxPackageDocumentBytes'],
			'maximum cover bytes' => ['maxCoverBytes'],
		];
	}

	/**
	 * @dataProvider positiveLimitProvider
	 */
	public function testRejectsNonPositiveLimit(string $property): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage(sprintf('%s must be greater than zero.', $property));

		new EPubArchiveLimits(...[$property => 0]);
	}

	public function testRejectsNegativeLimit(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('maxArchiveBytes must be greater than zero.');

		new EPubArchiveLimits(maxArchiveBytes: -1);
	}

	public function testRejectsTotalLimitBelowPerEntryLimit(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('total uncompressed size limit must be at least the per-entry limit');

		new EPubArchiveLimits(
			maxTotalUncompressedBytes: 1023,
			maxEntryUncompressedBytes: 1024,
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function specializedLimitProvider(): array {
		return [
			'container XML limit' => ['maxContainerXmlBytes'],
			'package document limit' => ['maxPackageDocumentBytes'],
			'cover limit' => ['maxCoverBytes'],
		];
	}

	/**
	 * @dataProvider specializedLimitProvider
	 */
	public function testRejectsSpecializedLimitAbovePerEntryLimit(string $property): void {
		$arguments = [
			'maxTotalUncompressedBytes' => 1024,
			'maxEntryUncompressedBytes' => 1024,
			'maxContainerXmlBytes' => 512,
			'maxPackageDocumentBytes' => 512,
			'maxCoverBytes' => 512,
		];
		$arguments[$property] = 1025;

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Specialized EPUB entry limits must not exceed the general per-entry limit.');

		new EPubArchiveLimits(...$arguments);
	}
}
