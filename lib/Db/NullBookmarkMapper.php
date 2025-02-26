<?php

namespace OCA\Epubviewer\Db;

use OCA\Epubviewer\Utility\Time;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;

/**
 * A null implementation of BookmarkMapper for anonymous users
 * @template-extends BookmarkMapper
 */
class NullBookmarkMapper extends BookmarkMapper {

	public function __construct(IDBConnection $db, Time $time) {
		parent::__construct($db, 'anonymous', $time);
	}

	/**
	 * @param Bookmark $entity
	 * @return Bookmark
	 */
	public function update($entity): Entity {
		return $entity;
	}

	/**
	 * @param Bookmark $entity
	 * @return Bookmark
	 */
	public function insert($entity): Entity {
		return $entity;
	}

	/**
	 * @brief get bookmarks for $fileId
	 * @param int $fileId
	 * @param string|null $name
	 * @param string|null $type
	 * @return array<Bookmark>
	 */
	public function get(int $fileId, ?string $name = null, ?string $type = null): array {
		return [];
	}

	/**
	 * @brief no-op for anonymous users
	 *
	 * @param int $fileId
	 * @param string $name
	 * @param string $value
	 * @param string|null $type
	 * @param string|null $content
	 *
	 * @return Bookmark an empty bookmark
	 */
	public function set(int $fileId, string $name, string $value, ?string $type = null, ?string $content = null) {
		$bookmark = new Bookmark();
		$bookmark->setFileId($fileId);
		$bookmark->setUserId('anonymous');
		$bookmark->setType($type ?? 'bookmark');
		$bookmark->setName($name);
		$bookmark->setValue($value);
		$bookmark->setContent($content);
		$bookmark->setLastModified(time() * 1000);
		
		return $bookmark;
	}

	public function deleteForFileId(int $fileId): void {
		// No-op for anonymous users
	}

	public function deleteForUserId(string $userId): void {
		// No-op for anonymous users
	}

	/**
	 * @return Bookmark[]
	 *
	 * @psalm-return array<Bookmark>
	 */
	public function findAll(int $fileId): array {
		return [];
	}

	/**
	 * @return Bookmark[]
	 *
	 * @psalm-return array<Bookmark>
	 */
	public function findAllForUser(): array {
		return [];
	}
	
	/**
	 * @param Entity $entity the entity to delete
	 */
	public function delete(Entity $entity): Entity {
		return $entity;
	}
} 