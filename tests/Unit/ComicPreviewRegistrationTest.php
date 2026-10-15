<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Preview\CBRPreview;
use OCA\Epubviewer\Preview\CBZPreview;
use OCP\Files\FileInfo;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ComicPreviewRegistrationTest extends TestCase {
	public function testProvidersExposeTheirComicArchiveMimePatterns(): void {
		$logger = $this->createMock(LoggerInterface::class);

		self::assertSame(
			'/^(?:application\/x-cbz|application\/comicbook\+zip)$/',
			(new CBZPreview($logger))->getMimeType(),
		);
		self::assertSame(
			'/^(?:application\/x-cbr|application\/comicbook\+rar)$/',
			(new CBRPreview($logger))->getMimeType(),
		);
	}

	public function testCbrProviderAvailabilityMatchesRarExtension(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$file = $this->createMock(FileInfo::class);

		self::assertSame(extension_loaded('rar'), (new CBRPreview($logger))->isAvailable($file));
	}

	public function testApplicationRegistersCbzUnconditionallyAndCbrConditionally(): void {
		$contents = file_get_contents(dirname(__DIR__, 2) . '/lib/AppInfo/Application.php');
		self::assertIsString($contents);

		$cbz = '\\OCA\\Epubviewer\\Preview\\CBZPreview::class';
		$cbr = '\\OCA\\Epubviewer\\Preview\\CBRPreview::class';
		self::assertSame(1, substr_count($contents, $cbz));
		self::assertSame(1, substr_count($contents, $cbr));
		self::assertMatchesRegularExpression(
			'/registerPreviewProvider\(\s*\\\\OCA\\\\Epubviewer\\\\Preview\\\\CBZPreview::class,\s*\'\/\^\(\?:application\\\\\/x-cbz\|application\\\\\/comicbook\\\\\+zip\)\$\/\',\s*\);/s',
			$contents,
		);
		self::assertMatchesRegularExpression(
			'/if \(extension_loaded\(\'rar\'\)\) \{\s*\$context->registerPreviewProvider\(\s*\\\\OCA\\\\Epubviewer\\\\Preview\\\\CBRPreview::class,\s*\'\/\^\(\?:application\\\\\/x-cbr\|application\\\\\/comicbook\\\\\+rar\)\$\/\',\s*\);\s*\}/s',
			$contents,
		);
	}
}
