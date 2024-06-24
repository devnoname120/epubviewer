<?php


namespace OCA\Epubviewer\Db;

use JsonSerializable;

class Preference extends ReaderEntity implements JsonSerializable {

	protected $userId;  // user for whom this preference is valid
	protected $scope;   // scope (default or specific renderer)
	protected $fileId;  // file for which this preference is set
	protected $name;    // preference name
	protected $value;   // preference value
	protected $lastModified;    // modification timestamp

	public function jsonSerialize() {
		return [
			'id' => $this->getId(),
			'scope' => $this->getScope(),
			'fileId' => $this->getFileId(),
			'name' => $this->getName(),
			'value' => $this->conditional_json_decode($this->getValue()),
			'lastModified' => $this->getLastModified(),
		];
	}

	/**
	 * @psalm-return array{name: mixed, value: mixed}
	 */
	public function toService(): array {
		return [
			'name' => $this->getName(),
			'value' => $this->conditional_json_decode($this->getValue()),
		];
	}
}
