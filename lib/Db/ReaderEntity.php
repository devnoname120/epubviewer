<?php

namespace OCA\Epubviewer\Db;

use OCP\AppFramework\Db\Entity;

class ReaderEntity extends Entity {

	/** @var int */
	protected int $lastModified;

	public function __construct() {
		$this->addType('lastModified', 'integer');
	}

	public static function conditional_json_decode($el) {
		$result = json_decode($el);
		if (json_last_error() === JSON_ERROR_NONE) {
			return $result;
		} else {
			return $el;
		}
	}

	public function getLastModified(): int {
		return $this->lastModified;
	}

	public function setLastModified(int $lastModified): void {
		$this->lastModified = $lastModified;
	}
}
