<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class Nextcloud34CompatibilityTest extends TestCase {
	/**
	 * @return array<string, array{string, string}>
	 */
	public static function removedServerGetterProvider(): array {
		return [
			'legacy app manager getter' => ['getAppManager', 'OCP\App\IAppManager'],
			'legacy CSP nonce manager getter' => ['getContentSecurityPolicyNonceManager', 'OCP\Server::get'],
		];
	}

	/**
	 * @dataProvider removedServerGetterProvider
	 */
	public function testAppDoesNotCallServerGettersRemovedInNextcloud34(string $methodName, string $replacement): void {
		$matches = [];

		foreach ($this->appPhpFiles() as $file) {
			$contents = file_get_contents($file->getPathname());
			self::assertIsString($contents);

			if (preg_match('/->' . preg_quote($methodName, '/') . '\s*\(/', $contents) === 1) {
				$matches[] = $this->relativePath($file);
			}
		}

		self::assertSame(
			[],
			$matches,
			sprintf(
				'Nextcloud 34 removed OC\Server::%s(); use %s instead. Offending files: %s',
				$methodName,
				$replacement,
				implode(', ', $matches),
			),
		);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function readerTemplateRevisionProvider(): array {
		return [
			'comic book reader' => ['templates/cbreader.php', '0048'],
			'epub reader' => ['templates/epubviewer.php', '0072'],
			'pdf reader' => ['templates/pdfreader.php', '0134'],
		];
	}

	/**
	 * @dataProvider readerTemplateRevisionProvider
	 */
	public function testReaderTemplateRevisionStaysInTemplate(string $templatePath, string $revision): void {
		$contents = file_get_contents($this->repoRoot() . '/' . $templatePath);
		self::assertIsString($contents);

		self::assertStringContainsString('$revision = \'' . $revision . '\';', $contents);
		self::assertStringContainsString('$version = $_[\'appVersion\'] . \'.\' . $revision;', $contents);
		self::assertStringNotContainsString('$_[\'version\']', $contents);
	}

	/**
	 * @return iterable<SplFileInfo>
	 */
	private function appPhpFiles(): iterable {
		foreach (['lib', 'templates'] as $directory) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($this->repoRoot() . '/' . $directory),
			);

			foreach ($iterator as $file) {
				if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
					yield $file;
				}
			}
		}
	}

	private function relativePath(SplFileInfo $file): string {
		return substr($file->getPathname(), strlen($this->repoRoot()) + 1);
	}

	private function repoRoot(): string {
		return dirname(__DIR__, 2);
	}
}
