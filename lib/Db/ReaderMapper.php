<?php

namespace OCA\Epubviewer\Db;

use OCA\Epubviewer\Utility\Time;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;

use OCP\IDBConnection;

abstract class ReaderMapper extends QBMapper {

	/**
	 * @var Time
	 */
	private $time;

	public function __construct(IDBConnection $db, $table, $entity, Time $time) {
		parent::__construct($db, $table, $entity);
		$this->time = $time;
	}

	public function update(Entity $entity): Entity {
		$entity->setLastModified($this->time->getMicroTime());
		return parent::update($entity);
	}

	public function insert(Entity $entity): Entity {
		$entity->setLastModified($this->time->getMicroTime());
		return parent::insert($entity);
	}
}
