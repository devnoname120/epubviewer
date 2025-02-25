<?php

namespace OCA\Epubviewer\Db;

use OCA\Epubviewer\Utility\Time;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template T of ReaderEntity
 * @extends QBMapper<T>
 */
abstract class ReaderMapper extends QBMapper {

	private Time $time;

	public function __construct(IDBConnection $db, $table, $entity, Time $time) {
		parent::__construct($db, $table, $entity);
		$this->time = $time;
	}

	/**
	 * @param T $entity
	 * @return T
	 */
	public function update($entity): Entity {
		$entity->setLastModified((int)$this->time->getMicroTime());
		/** @var T $updated */
		$updated = parent::update($entity);
		return $updated;
	}

	/**
	 * @param T $entity
	 * @return T
	 */
	public function insert($entity): Entity {
		$entity->setLastModified((int)$this->time->getMicroTime());
		/** @var T $inserted */
		$inserted = parent::insert($entity);
		return $inserted;
	}
}
