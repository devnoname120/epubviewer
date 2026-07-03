<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use PHPUnit\Framework\TestCase;

class Nextcloud35CompatibilityTest extends TestCase {
	public function testAppMetadataSupportsNextcloud35(): void {
		$infoXml = simplexml_load_file($this->repoRoot() . '/appinfo/info.xml');
		self::assertNotFalse($infoXml);

		$dependencies = $infoXml->dependencies;
		self::assertSame('33', (string)$dependencies->nextcloud['min-version']);
		self::assertSame('35', (string)$dependencies->nextcloud['max-version']);
		self::assertSame('8.2', (string)$dependencies->php['min-version']);
		self::assertSame('8.5', (string)$dependencies->php['max-version']);
	}

	public function testStaticAnalysisRunsAgainstNextcloud35(): void {
		$workflow = file_get_contents($this->repoRoot() . '/.github/workflows/static-code-analysis.yml');
		self::assertIsString($workflow);

		self::assertMatchesRegularExpression('/nc_ref:\s+stable33\s+php_version:\s+\'8\.2\'/s', $workflow);
		self::assertMatchesRegularExpression('/nc_ref:\s+stable34\s+php_version:\s+\'8\.2\'/s', $workflow);
		self::assertMatchesRegularExpression('/nc_ref:\s+stable35\s+php_version:\s+\'8\.3\'/s', $workflow);
		self::assertStringContainsString('php-version: ${{ matrix.php_version }}', $workflow);
	}

	public function testPhpCsLintUsesSupportedPhpVersion(): void {
		$workflow = file_get_contents($this->repoRoot() . '/.github/workflows/lint-php-cs.yml');
		self::assertIsString($workflow);

		self::assertStringNotContainsString('php-version: 8.1', $workflow);
		self::assertStringContainsString('php-version: 8.2', $workflow);
	}

	private function repoRoot(): string {
		return dirname(__DIR__, 2);
	}
}
