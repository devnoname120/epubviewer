<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReaderSecurityBoundaryTest extends TestCase {
	/**
	 * @return array<string, array{string, string}>
	 */
	public static function stateChangingControllerMethodProvider(): array {
		return [
			'bookmark set' => ['lib/Controller/BookmarkController.php', 'set'],
			'bookmark cursor set' => ['lib/Controller/BookmarkController.php', 'setCursor'],
			'bookmark delete' => ['lib/Controller/BookmarkController.php', 'delete'],
			'bookmark cursor delete' => ['lib/Controller/BookmarkController.php', 'deleteCursor'],
			'preference set' => ['lib/Controller/PreferenceController.php', 'set'],
			'default preference set' => ['lib/Controller/PreferenceController.php', 'setDefault'],
			'preference delete' => ['lib/Controller/PreferenceController.php', 'delete'],
			'default preference delete' => ['lib/Controller/PreferenceController.php', 'deleteDefault'],
		];
	}

	/**
	 * @dataProvider stateChangingControllerMethodProvider
	 */
	public function testStateChangingControllerMethodsRequireCsrfAndAllowRegularUsers(string $path, string $method): void {
		$attributes = $this->methodAttributes($path, $method);

		self::assertStringContainsString('NoAdminRequired', $attributes);
		self::assertStringNotContainsString('NoCSRFRequired', $attributes);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function readOnlyControllerMethodProvider(): array {
		return [
			'bookmark get' => ['lib/Controller/BookmarkController.php', 'get'],
			'bookmark cursor get' => ['lib/Controller/BookmarkController.php', 'getCursor'],
			'preference get' => ['lib/Controller/PreferenceController.php', 'get'],
			'default preference get' => ['lib/Controller/PreferenceController.php', 'getDefault'],
		];
	}

	/**
	 * @dataProvider readOnlyControllerMethodProvider
	 */
	public function testReadOnlyControllerMethodsKeepCsrfExemption(string $path, string $method): void {
		$attributes = $this->methodAttributes($path, $method);

		self::assertStringContainsString('NoAdminRequired', $attributes);
		self::assertStringContainsString('NoCSRFRequired', $attributes);
	}

	public function testEveryReaderWriteSendsTheRequestTokenHeader(): void {
		$contents = $this->readRepoFile('src/ready.ts');

		self::assertStringContainsString("requesttoken: \$session.attr('data-requesttoken') || ''", $contents);
		self::assertSame(4, preg_match_all("/writeRequest\\(\\s*'POST'/", $contents));
		self::assertSame(4, preg_match_all("/writeRequest\\(\\s*'DELETE'/", $contents));
		self::assertStringNotContainsString('$.post(', $contents);
		self::assertStringNotContainsString('$.delete(', $contents);
		self::assertStringNotContainsString("\$session.data('nonce')", $contents);
	}

	public function testObsoleteTokenlessDeleteHelperIsNotLoaded(): void {
		foreach (['templates/epubviewer.php', 'templates/pdfreader.php', 'templates/cbreader.php'] as $path) {
			self::assertStringNotContainsString('put-delete.js', $this->readRepoFile($path));
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function readerTemplateProvider(): array {
		return [
			'comic book reader' => ['templates/cbreader.php'],
			'epub reader' => ['templates/epubviewer.php'],
			'pdf reader' => ['templates/pdfreader.php'],
		];
	}

	/**
	 * @dataProvider readerTemplateProvider
	 */
	public function testReaderTemplateEscapesScalarAttributesAndExposesRequestToken(string $path): void {
		$contents = $this->readRepoFile($path);

		self::assertStringContainsString("\$requestToken = \$_['requesttoken'];", $contents);
		self::assertStringContainsString("data-requesttoken='<?php p(\$requestToken); ?>'", $contents);
		self::assertStringNotContainsString('data-nonce=', $contents);

		$scalarAttributes = [
			'downloadlink' => 'downloadLink',
			'fileid' => 'fileId',
			'filetype' => 'fileType',
			'filename' => 'fileName',
			'version' => 'version',
			'scope' => 'scope',
		];
		foreach ($scalarAttributes as $attribute => $variable) {
			self::assertStringContainsString(
				"data-$attribute='<?php p(\$$variable); ?>'",
				$contents,
			);
			self::assertStringNotContainsString("print_unescaped(\$$variable)", $contents);
		}

		foreach (['cursor', 'defaults', 'preferences', 'annotations'] as $variable) {
			self::assertStringContainsString("print_unescaped(\$$variable)", $contents);
			self::assertStringNotContainsString("p(\$$variable)", $contents);
		}
	}

	private function methodAttributes(string $path, string $method): string {
		$contents = $this->readRepoFile($path);
		$pattern = '/(?<attributes>(?:\s*#\[[^\]]+\])+?)\s*public function '
			. preg_quote($method, '/') . '\s*\(/';

		self::assertSame(1, preg_match($pattern, $contents, $matches));

		return $matches['attributes'];
	}

	private function readRepoFile(string $path): string {
		$contents = file_get_contents($this->repoRoot() . '/' . $path);
		self::assertIsString($contents);

		return $contents;
	}

	private function repoRoot(): string {
		return dirname(__DIR__, 2);
	}
}
