<?php

namespace OCA\Epubviewer\Db;

use OCA\Epubviewer\Utility\Time;
use OCP\IDBConnection;

class PreferenceMapper extends ReaderMapper {

	public function __construct(IDBConnection $db, $userId, Time $time) {
		parent::__construct($db, 'reader_prefs', Preference::class, $time);
		$this->userId = $userId;
	}

	/**
	 * @brief get preferences for $scope+$fileId+$userId(+$name)
	 *
	 * @param string $scope
	 * @param int $fileId
	 * @param string $name
	 * @return array
	 */
	public function get($scope, $fileId, $name = null) {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from($this->getTableName())
			->where($query->expr()->eq('scope', $query->createNamedParameter($scope)))
			->andWhere($query->expr()->eq('file_id', $query->createNamedParameter($fileId)))
			->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($this->userId)));

		if (!empty($name)) {
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

	/* currently not used*/
	public function deleteForFileId($fileId): void {
		$sql = "SELECT * FROM `*PREFIX*reader_prefs` WHERE file_id=?";
		$args = [$fileId];
		array_map(
			function ($entity) {
				$this->delete($entity);
			}, $this->findEntities($sql, $args)
		);
	}

	/* currently not used*/
	public function deleteForUserId($userId): void {
		$sql = "SELECT * FROM `*PREFIX*reader_prefs` WHERE user_id=?";
		$args = [$userId];
		array_map(
			function ($entity) {
				$this->delete($entity);
			}, $this->findEntities($sql, $args)
		);
	}
}
