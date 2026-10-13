<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use JsonException;
use OCA\Epubviewer\Service\ReaderResponseBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ReaderStateEncodingTest extends TestCase {
	public function testReaderStateRoundTripsJsonTypesAndEscapesHtml(): void {
		$state = ['zero' => 0, 'false' => false, 'empty' => [], 'text' => '<example> & "quoted"'];
		$encoded = $this->encode($state);
		self::assertStringNotContainsString('<example>', $encoded);
		self::assertStringContainsString('&lt;example&gt;', $encoded);
		self::assertSame($state, json_decode(htmlspecialchars_decode($encoded, ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR));
	}

	public function testMalformedUtf8IsReplacedWithoutBreakingReaderState(): void {
		$encoded = $this->encode(['text' => "before\xffafter"]);
		self::assertSame(['text' => "before\u{FFFD}after"], json_decode(htmlspecialchars_decode($encoded, ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR));
	}

	public function testUnencodableValuesRaiseAnExplicitJsonError(): void {
		$this->expectException(JsonException::class);
		$this->encode(['value' => INF]);
	}

	private function encode(array $state): string {
		// Exercise the state encoding without requiring Nextcloud's global CSP container.
		$builder = (new ReflectionClass(ReaderResponseBuilder::class))->newInstanceWithoutConstructor();
		return (new ReflectionMethod(ReaderResponseBuilder::class, 'toJson'))->invoke($builder, $state);
	}
}
