<?php

namespace OCA\Epubviewer\Db;

use OCA\Epubviewer\Utility\Time;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Preference>
 */
class PreferenceMapper extends QBMapper {

	protected string $userId;
	private Time $time;

	public function __construct(IDBConnection $db, Time $time, ?string $userId) {
		parent::__construct($db, 'reader_prefs', Preference::class);
		$this->userId = $userId ?? '';
		$this->time = $time;
	}

	/**
	 * @param Preference $entity
	 * @return Preference
	 */
	public function update($entity): Entity {
		$entity->setLastModified($this->time->getMicroTime());
		/** @var Preference $updated */
		$updated = parent::update($entity);
		return $updated;
	}

	/**
	 * @param Preference $entity
	 * @return Preference
	 */
	public function insert($entity): Entity {
		$entity->setLastModified($this->time->getMicroTime());
		/** @var Preference $inserted */
		$inserted = parent::insert($entity);
		return $inserted;
	}

	/**
	 * Get preferences for $scope+$fileId+$userId(+$name)
	 *
	 * @param string $scope
	 * @param int $fileId
	 * @param string|null $name
	 * @return array
	 */
	public function get($scope, $fileId, $name = null) {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('scope', $query->createNamedParameter($scope)))
			->andWhere($query->expr()->eq('file_id', $query->createNamedParameter($fileId)))
			->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($this->userId)));

		if ($name !== null && !empty($name)) {
			$query->andWhere($query->expr()->eq('name', $query->createNamedParameter($name)));
		}

		return $this->findEntities($query);
	}

	/**
	 * @brief write preference to database
	 *
	 * @param string $scope
	 * @param int $fileId
	 * @param string $name
	 * @param string $value
	 *
	 * @return Preference the newly created or updated preference
	 */
	public function set($scope, $fileId, $name, $value) {

		$result = $this->get($scope, $fileId, $name);

		if (empty($result)) {

			$preference = new Preference();
			$preference->setScope($scope);
			$preference->setFileId($fileId);
			$preference->setUserId($this->userId);
			$preference->setName($name);
			$preference->setValue($value);

			$this->insert($preference);
		} else {
			$preference = $result[0];
			$preference->setValue($value);

			$this->update($preference);
		}

		return $preference;
	}

	public function deleteForFileId($fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
		$qb->executeStatement();
	}

	public function deleteForUserId($userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * @return Preference[]
	 *
	 * @psalm-return array<Preference>
	 */
	public function findAll($fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
		
		return $this->findEntities($qb);
	}

	/**
	 * @return Preference[]
	 *
	 * @psalm-return array<Preference>
	 */
	public function findAllForUser(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));
		
		return $this->findEntities($qb);
	}
}
