<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Preview\ComicArchiveLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComicArchiveLimitsTest extends TestCase {
	/** @return array<string, array{string}> */
	public static function positiveLimitProvider(): array {
		return [
			'maximum temporary archive bytes' => ['maxTemporaryArchiveBytes'],
			'maximum entry count' => ['maxEntryCount'],
			'maximum central directory bytes' => ['maxCentralDirectoryBytes'],
			'maximum entry name bytes' => ['maxEntryNameBytes'],
			'maximum path depth' => ['maxPathDepth'],
			'maximum cover bytes' => ['maxCoverBytes'],
			'maximum compression ratio' => ['maxCompressionRatio'],
			'minimum ratio bytes' => ['minCompressionRatioBytes'],
			'maximum solid prefix bytes' => ['maxSolidPrefixBytes'],
			'maximum image dimension' => ['maxImageDimension'],
			'maximum image pixels' => ['maxImagePixels'],
		];
	}

	#[DataProvider('positiveLimitProvider')]
	public function testRejectsNonPositiveLimits(string $name): void {
		$this->expectException(\InvalidArgumentException::class);
		new ComicArchiveLimits(...[$name => 0]);
	}

	public function testRejectsCopyLimitThatCannotBeIncrementedSafely(): void {
		$this->expectException(\InvalidArgumentException::class);
		new ComicArchiveLimits(maxTemporaryArchiveBytes: PHP_INT_MAX);
	}

	public function testRejectsCoverLimitThatCannotBeIncrementedSafely(): void {
		$this->expectException(\InvalidArgumentException::class);
		new ComicArchiveLimits(maxCoverBytes: PHP_INT_MAX, maxSolidPrefixBytes: PHP_INT_MAX);
	}

	public function testSolidPrefixMustContainCover(): void {
		$this->expectException(\InvalidArgumentException::class);
		new ComicArchiveLimits(maxCoverBytes: 2, maxSolidPrefixBytes: 1);
	}
}
