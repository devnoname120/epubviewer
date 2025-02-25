<?php

namespace OCA\Epubviewer\Service;

use OCA\Epubviewer\Db\ReaderEntity;
use OCA\Epubviewer\Db\ReaderMapper;

/**
 * @template T of ReaderEntity
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
