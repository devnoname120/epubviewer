<?php

namespace OCA\Epubviewer\Service;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;

/**
 * @template T of Entity
 */
abstract class Service {

	/**
	 * @var QBMapper<T>
	 */
	protected $mapper;

	/**
	 * @param QBMapper<T> $mapper
	 */
	public function __construct(QBMapper $mapper) {
		$this->mapper = $mapper;
	}
}
