<?php

namespace OCA\Epubviewer\Service;

use OCA\Epubviewer\Db\ReaderMapper;
use OCP\AppFramework\Db\Entity;

/**
 * @template T of Entity
 */
abstract class Service {

	/**
	 * @var ReaderMapper<T>
	 */
	protected $mapper;

	/**
	 * @param ReaderMapper<T> $mapper
	 */
	public function __construct(ReaderMapper $mapper) {
		$this->mapper = $mapper;
	}
}
