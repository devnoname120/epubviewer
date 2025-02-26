<?php

namespace OCA\Epubviewer\Db;

use OCA\Epubviewer\Utility\Time;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Bookmark>
 */
class BookmarkMapper extends QBMapper {

	private string $userId;
	private Time $time;

	/**
	 * @param IDbConnection $db
	 * @param string|null $userId
	 * @param Time $time
	 */
	public function __construct(IDBConnection $db, ?string $userId, Time $time) {
		parent::__construct($db, 'reader_bookmarks', Bookmark::class);
		$this->userId = $userId ?? '';
		$this->time = $time;
	}

	/**
	 * @param Bookmark $entity
	 * @return Bookmark
	 */
	public function update($entity): Entity {
		$entity->setLastModified($this->time->getMicroTime());
		/** @var Bookmark $updated */
		$updated = parent::update($entity);
		return $updated;
	}

	/**
	 * @param Bookmark $entity
	 * @return Bookmark
	 */
	public function insert($entity): Entity {
		$entity->setLastModified($this->time->getMicroTime());
		/** @var Bookmark $inserted */
		$inserted = parent::insert($entity);
		return $inserted;
	}

	/**
	 * @brief get bookmarks for $fileId+$userId(+$name)
	 * @param int $fileId
	 * @param string|null $name
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

	public function deleteForFileId(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
		$qb->executeStatement();
	}

	public function deleteForUserId(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * @return Bookmark[]
	 *
	 * @psalm-return array<Bookmark>
	 */
	public function findAll(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));

		return $this->findEntities($qb);
	}

	/**
	 * @return Bookmark[]
	 *
	 * @psalm-return array<Bookmark>
	 */
	public function findAllForUser(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));

		return $this->findEntities($qb);
	}
}
