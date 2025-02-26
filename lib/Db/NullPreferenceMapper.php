<?php

namespace OCA\Epubviewer\Db;

use OCA\Epubviewer\Utility\Time;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;

/**
 * A null implementation of PreferenceMapper for anonymous users
 * @template-extends PreferenceMapper
 */
class NullPreferenceMapper extends PreferenceMapper {

	public function __construct(IDBConnection $db, Time $time) {
		parent::__construct($db, $time, 'anonymous');
	}

	/**
	 * @param Preference $entity
	 * @return Preference
	 */
	public function update($entity): Entity {
		return $entity;
	}

	/**
	 * @param Preference $entity
	 * @return Preference
	 */
	public function insert($entity): Entity {
		return $entity;
	}

	/**
	 * @brief get preferences for $scope+$fileId (no-op for anonymous users)
	 *
	 * @param string $scope
	 * @param int $fileId
	 * @param string $name
	 * @return array
	 */
	public function get($scope, $fileId, $name = null) {
		// For defaults, we should get the actual defaults
		if ($fileId === 0) {
			return parent::get($scope, $fileId, $name);
		}
		
		return [];
	}

	/**
	 * @brief write preference to database (no-op for anonymous users)
	 *
	 * @param string $scope
	 * @param int $fileId
	 * @param string $name
	 * @param string $value
	 *
	 * @return Preference the newly created or updated preference
	 */
	public function set($scope, $fileId, $name, $value) {
		// For defaults, we should actually set them
		if ($fileId === 0) {
			return parent::set($scope, $fileId, $name, $value);
		}
		
		$preference = new Preference();
		$preference->setScope($scope);
		$preference->setFileId($fileId);
		$preference->setUserId('anonymous');
		$preference->setName($name);
		$preference->setValue($value);
		$preference->setLastModified(time() * 1000);
		
		return $preference;
	}

	public function deleteForFileId($fileId): void {
		// No-op for anonymous users
	}

	public function deleteForUserId($userId): void {
		// No-op for anonymous users
	}

	/**
	 * @return Preference[]
	 *
	 * @psalm-return array<Preference>
	 */
	public function findAll($fileId): array {
		return [];
	}

	/**
	 * @return Preference[]
	 *
	 * @psalm-return array<Preference>
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