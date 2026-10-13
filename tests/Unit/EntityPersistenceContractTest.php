<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit;

use OCA\Epubviewer\Db\Bookmark;
use OCA\Epubviewer\Db\Preference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntityPersistenceContractTest extends TestCase {
	public function testNewEntitiesKeepTheirDatabaseAssignedIdUnset(): void {
		foreach ([new Bookmark(), new Preference()] as $entity) {
			self::assertNull($entity->getId());
			self::assertSame([], $entity->getUpdatedFields());
		}
	}

	public function testBookmarkHydratesDatabaseScalarsAndNullableContent(): void {
		$bookmark = Bookmark::fromRow([
			'id' => '17', 'user_id' => 'alice', 'file_id' => '42',
			'type' => 'bookmark', 'name' => 'position', 'value' => '0',
			'content' => null, 'last_modified' => '1234567890123456',
		]);
		self::assertSame(17, $bookmark->getId());
		self::assertSame(42, $bookmark->getFileId());
		self::assertSame('alice', $bookmark->getUserId());
		self::assertNull($bookmark->getContent());
		self::assertSame('1234567890123456', $bookmark->getLastModified());
		self::assertSame(0, $bookmark->toService()['value']);
		self::assertSame([], $bookmark->getUpdatedFields());
	}

	public function testPreferenceHydratesTheDefaultFileIdAndPreservesTimestampPrecision(): void {
		$preference = Preference::fromRow([
			'id' => '18', 'user_id' => 'alice', 'file_id' => '0',
			'scope' => 'epubviewer', 'name' => 'font_size', 'value' => '0',
			'last_modified' => '1234567890123456',
		]);
		self::assertSame(18, $preference->getId());
		self::assertSame(0, $preference->getFileId());
		self::assertSame('1234567890123456', $preference->getLastModified());
		self::assertSame(0, $preference->jsonSerialize()['value']);
		self::assertSame([], $preference->getUpdatedFields());
	}

	/** @return array<string, array{?string, mixed}> */
	public static function storedValues(): array {
		return [
			'absent' => [null, null],
			'empty string' => ['', ''],
			'JSON zero' => ['0', 0],
			'JSON string zero' => ['"0"', '0'],
			'JSON false' => ['false', false],
			'JSON null' => ['null', null],
			'empty list' => ['[]', []],
			'legacy plain text' => ['a chapter location', 'a chapter location'],
		];
	}

	#[DataProvider('storedValues')]
	public function testStoredJsonValuesKeepTheirTypes(?string $stored, mixed $expected): void {
		self::assertSame($expected, Bookmark::conditional_json_decode($stored));
		self::assertSame($expected, Preference::conditional_json_decode($stored));
	}
}
