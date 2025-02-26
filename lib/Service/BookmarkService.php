<?php

namespace OCA\Epubviewer\Service;

use OCA\Epubviewer\Db\Bookmark;
use OCA\Epubviewer\Db\BookmarkMapper;

/**
 * @template-extends Service<Bookmark>
 */
class BookmarkService extends Service {

	// "bookmark" name to use for the cursor (current reading position)
	public const CURSOR = '__CURSOR__';
	public const bookmark_type = 'bookmark';

	private BookmarkMapper $bookmarkMapper;

	public function __construct(BookmarkMapper $bookmarkMapper) {
		parent::__construct($bookmarkMapper);
		$this->bookmarkMapper = $bookmarkMapper;
	}

	/**
	 * Get bookmarks for $fileId
	 *
	 * @param int $fileId
	 * @param string|null $name
	 * @param string|null $type
	 * @return array
	 */
	public function get(int $fileId, ?string $name = null, ?string $type = null): array {
		/** @var array<Bookmark> */
		$result = $this->bookmarkMapper->get($fileId, $name, $type);
		return array_map(
			function ($entity) {
				return $entity->toService();
			}, $result);
	}

	/**
	 * @brief write bookmark
	 *
	 * position type is format-dependent, eg CFI for epub, page number for CBR/CBZ, etc
	 *
	 * @param int $fileId
	 * @param string $name
	 * @param string $value
	 *
	 * @return Bookmark
	 */
	public function set(int $fileId, string $name, string $value, ?string $type = null, ?string $content = null): Bookmark {
		return $this->bookmarkMapper->set($fileId, $name, $value, $type, $content);
	}

	/**
	 * @brief get cursor (current position in book)
	 *
	 * @param int $fileId
	 * @return array|null
	 */
	public function getCursor($fileId) {
		$result = $this->get($fileId, static::CURSOR);
		if (count($result) === 1) {
			return $result[0];
		}
		return null;
	}

	/**
	 * @brief set cursor (current position in book)
	 *
	 * @param int $fileId
	 * @param string $value
	 *
	 * @return Bookmark
	 */
	public function setCursor(int $fileId, string $value): Bookmark {
		return $this->bookmarkMapper->set($fileId, static::CURSOR, $value, static::bookmark_type);
	}

	/**
	 * @brief delete bookmark
	 *
	 * @param int $fileId
	 * @param string $name
	 */
	public function delete(int $fileId, string $name, ?string $type = null): void {
		foreach ($this->bookmarkMapper->get($fileId, $name, $type) as $bookmark) {
			$this->bookmarkMapper->delete($bookmark);
		}
	}

	/**
	 * @brief delete cursor
	 *
	 * @param int $fileId
	 */
	public function deleteCursor($fileId): void {
		$this->delete($fileId, static::CURSOR, static::bookmark_type);
	}
}
