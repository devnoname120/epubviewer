<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReaderSecurityTest extends TestCase {
	public function testEpubContentIframeIsSandboxedWithoutScriptPermission(): void {
		$contents = file_get_contents($this->repoRoot() . '/js/epubjs/epub.min.js');
		self::assertIsString($contents);

		self::assertStringContainsString(
			'this.iframe.setAttribute("sandbox","allow-same-origin")',
			$contents,
		);
		self::assertStringNotContainsString('sandbox","allow-same-origin allow-scripts', $contents);
	}

	public function testUntrustedReaderTextIsNotRenderedAsHtml(): void {
		$contents = file_get_contents($this->repoRoot() . '/js/epubjs/reader.min.js');
		self::assertIsString($contents);

		self::assertStringContainsString('d.text(b),e.text(c),f.show()', $contents);
		self::assertStringNotContainsString('d.html(b),e.html(c)', $contents);
		self::assertStringContainsString('pop_content.textContent=c', $contents);
		self::assertStringNotContainsString('pop_content.innerHTML=c', $contents);
	}

	private function repoRoot(): string {
		return dirname(__DIR__, 2);
	}
}
