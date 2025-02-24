<?php

namespace OCA\Epubviewer\Db;

use OCA\Epubviewer\Utility\Time;

use OCP\IDBConnection;

/**
 * @template-extends ReaderMapper<Bookmark>
 */
class BookmarkMapper extends ReaderMapper {

	private string $userId;

	/**
	 * @param IDbConnection $db
	 * @param string $userId
	 * @param Time $time
	 */
	public function __construct(IDBConnection $db, string $userId, Time $time) {
		parent::__construct($db, 'reader_bookmarks', Bookmark::class, $time);
		$this->userId = $userId;
	}

	/**
	 * @brief get bookmarks for $fileId+$userId(+$name)
	 * @param int $fileId
	 * @param string $name
	 * @param string|null $type
	 * @return array<Bookmark>
	 */
	public function get(int $fileId, ?string $name = null, ?string $type = null): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('file_id', $query->createNamedParameter($fileId)))
			->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($this->userId)));

		if ($type !== null) {
			$query->andWhere($query->expr()->eq('type', $query->createNamedParameter($type)));
		}

		if ($name !== null) {
			$query->andWhere($query->expr()->eq('name', $query->createNamedParameter($name)));
		}

		return $this->findEntities($query);
	}

	/**
	 * @brief write bookmark to database
	 *
	 * @param int $fileId
	 * @param string $name
	 * @param string $value
	 * @param string|null $type
	 * @param string|null $content
	 *
	 * @return Bookmark the newly created or updated bookmark
	 */
	public function set(int $fileId, string $name, string $value, ?string $type = null, ?string $content = null) {
		$result = $this->get($fileId, $name);

		if (empty($result)) {
			// anonymous bookmarks are named after their contents
			if ($name === '') {
				$name = $value;
			}

			// default type is "bookmark"
			if ($type === null) {
				$type = 'bookmark';
			}

			$bookmark = new Bookmark();
			$bookmark->setFileId($fileId);
			$bookmark->setUserId($this->userId);
			$bookmark->setType($type);
			$bookmark->setName($name);
			$bookmark->setValue($value);
			$bookmark->setContent($content);

			$this->insert($bookmark);
		} else {
			$bookmark = $result[0];
			$bookmark->setValue($value);
			$bookmark->setContent($content);

			$this->update($bookmark);
		}

		return $bookmark;
	}

	/* currently not used */
	public function deleteForFileId($fileId): void {
		$sql = "SELECT * FROM `*PREFIX*reader_bookmarks` WHERE file_id=?";
		$args = [$fileId];
		array_map(
			function ($entity) {
				$this->delete($entity);
			}, $this->findEntities($sql, $args)
		);
	}

	/* currently not used */
	public function deleteForUserId($userId): void {
		$sql = "SELECT * FROM `*PREFIX*reader_bookmarks` WHERE user_id=?";
		$args = [$userId];
		array_map(
			function ($entity) {
				$this->delete($entity);
			}, $this->findEntities($sql, $args)
		);
	}
}
