<?php

namespace OCA\Epubviewer\Service;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;

/**
 * @template T of Entity
 */
abstract class Service {

	/**
	 * @param QBMapper<T> $mapper
	 */
	public function __construct(
		protected QBMapper $mapper
	) {
	}
}
