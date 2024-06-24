<?php

namespace OCA\Epubviewer\Service;

use OCA\Epubviewer\Db\ReaderMapper;

abstract class Service {

	protected $mapper;

	public function __construct(ReaderMapper $mapper) {
		$this->mapper = $mapper;
	}
}
