<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Preview\RarMetadataPreflight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RarMetadataPreflightTest extends TestCase {
	/** @var list<string> */
	private array $temporaryFiles = [];

	protected function tearDown(): void {
		foreach ($this->temporaryFiles as $file) {
			@unlink($file);
		}
		$this->temporaryFiles = [];
	}

	public function testAcceptsSingleVolumeRar4Header(): void {
		RarMetadataPreflight::check($this->write($this->rar4(0)));
		self::assertTrue(true);
	}

	/** @return array<string, array{int, string}> */
	public static function rejectedRar4Flags(): array {
		return [
			'multi-volume archive' => [0x0001, 'multi-volume'],
			'header-encrypted archive' => [0x0080, 'header-encrypted'],
		];
	}

	#[DataProvider('rejectedRar4Flags')]
	public function testRejectsUnsafeRar4Flags(int $flags, string $message): void {
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage($message);
		RarMetadataPreflight::check($this->write($this->rar4($flags)));
	}

	public function testRejectsRar4WithoutMainHeader(): void {
		$data = "Rar!\x1a\x07\x00" . pack('vCvv', 0, 0x74, 0, 13) . str_repeat("\0", 6);
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('main header');
		RarMetadataPreflight::check($this->write($data));
	}

	public function testRejectsTruncatedRar4MainHeader(): void {
		$data = "Rar!\x1a\x07\x00" . pack('vCvv', 0, 0x73, 0, 13);
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('truncated');
		RarMetadataPreflight::check($this->write($data));
	}

	public function testAcceptsSingleVolumeRar5Header(): void {
		RarMetadataPreflight::check($this->write($this->rar5(1, 0)));
		self::assertTrue(true);
	}

	public function testAcceptsBoundedRar5GenericHeaderFields(): void {
		RarMetadataPreflight::check($this->write($this->rar5(1, 0, 0x0003)));
		self::assertTrue(true);
	}

	public function testRejectsMultiVolumeRar5Header(): void {
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('multi-volume');
		RarMetadataPreflight::check($this->write($this->rar5(1, 0x0001)));
	}

	public function testRejectsRar5VolumeNumberWithoutVolumeFlag(): void {
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('multi-volume');
		RarMetadataPreflight::check($this->write($this->rar5(1, 0x0002)));
	}

	public function testRejectsHeaderEncryptedRar5Archive(): void {
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('header-encrypted');
		RarMetadataPreflight::check($this->write($this->rar5(4)));
	}

	public function testRejectsRar5WithoutMainHeader(): void {
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('main header');
		RarMetadataPreflight::check($this->write($this->rar5(2)));
	}

	public function testRejectsOverlongRar5HeaderSizeInteger(): void {
		$data = "Rar!\x1a\x07\x01\x00" . pack('V', 0) . "\x80\x80\x80\x00";
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('variable integer');
		RarMetadataPreflight::check($this->write($data));
	}

	public function testRejectsTruncatedRar5Header(): void {
		$data = "Rar!\x1a\x07\x01\x00" . pack('V', 0) . $this->vint(8) . $this->vint(1);
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('truncated');
		RarMetadataPreflight::check($this->write($data));
	}

	public function testRejectsSelfExtractingPrefix(): void {
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('signature');
		RarMetadataPreflight::check($this->write('prefix' . $this->rar5(1, 0)));
	}

	public function testAcceptsRepositoryRar4AndRar5Fixtures(): void {
		$fixtures = dirname(__DIR__) . '/fixtures/rar/';
		RarMetadataPreflight::check($fixtures . 'sample-rar4.rar');
		RarMetadataPreflight::check($fixtures . 'sample-rar5.rar');
		self::assertTrue(true);
	}

	private function rar4(int $flags): string {
		return "Rar!\x1a\x07\x00" . pack('vCvv', 0, 0x73, $flags, 13) . str_repeat("\0", 6);
	}

	private function rar5(int $headerType, int $archiveFlags = 0, int $headerFlags = 0): string {
		$body = $this->vint($headerType) . $this->vint($headerFlags);
		if (($headerFlags & 0x0001) !== 0) {
			$body .= $this->vint(0);
		}
		if (($headerFlags & 0x0002) !== 0) {
			$body .= $this->vint(0);
		}
		if ($headerType === 1) {
			$body .= $this->vint($archiveFlags);
		}
		return "Rar!\x1a\x07\x01\x00" . pack('V', 0) . $this->vint(strlen($body)) . $body;
	}

	private function vint(int $value): string {
		$result = '';
		do {
			$byte = $value & 0x7f;
			$value >>= 7;
			$result .= chr($value === 0 ? $byte : $byte | 0x80);
		} while ($value !== 0);
		return $result;
	}

	private function write(string $data): string {
		$file = tempnam(sys_get_temp_dir(), 'epubviewer-rar-preflight-');
		self::assertIsString($file);
		$this->temporaryFiles[] = $file;
		self::assertSame(strlen($data), file_put_contents($file, $data));
		return $file;
	}
}
