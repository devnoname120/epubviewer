<?php


namespace OCA\Epubviewer\Db;

use JsonSerializable;

class Bookmark extends ReaderEntity implements JsonSerializable {

	protected $userId;  // user
	protected $fileId;  // book (identified by fileId) for which this mark is valid
	protected $type;    // type, defaults to "bookmark"
	protected $name;    // name, defaults to $location
	protected $value;   // bookmark value (format-specific, eg. page number for PDF, CFI for epub, etc)
	protected $content; // bookmark content (annotations, etc.), can be empty
	protected $lastModified;    // modification timestamp

	public function jsonSerialize() {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'fileId' => $this->getFileId(),
			'type' => $this->getType(),
			'name' => $this->getName(),
			'value' => static::conditional_json_decode($this->getValue()),
			'content' => static::conditional_json_decode($this->getContent()),
			'lastModified' => $this->getLastModified()
		];
	}

	/**
	 * @psalm-return array{name: string, type: string, value: mixed, content: mixed, lastModified: int}
	 */
	public function toService(): array {
		return [
			'name' => $this->getName(),
			'type' => $this->getType(),
			'value' => $this->conditional_json_decode($this->getValue()),
			'content' => $this->conditional_json_decode($this->getContent()),
			'lastModified' => $this->getLastModified(),
		];
	}
}
