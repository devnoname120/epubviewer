<?php

namespace OCA\Epubviewer\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getValue()
 * @method void setValue(string $value)
 * @method string|null getContent()
 * @method void setContent(?string $content)
 * @method string getLastModified()
 * @method void setLastModified(string $lastModified)
 */
class Bookmark extends Entity implements JsonSerializable {

	protected $userId;  // user
	protected $fileId;  // book (identified by fileId) for which this mark is valid
	protected $type;    // type, defaults to "bookmark"
	protected $name;    // name, defaults to $location
	protected $value;   // bookmark value (format-specific, eg. page number for PDF, CFI for epub, etc)
	protected $content; // bookmark content (annotations, etc.), can be empty
	protected $lastModified;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('fileId', Types::INTEGER);
		$this->addType('type', Types::STRING);
		$this->addType('name', Types::STRING);
		$this->addType('value', Types::STRING);
		$this->addType('content', Types::STRING);
		$this->addType('lastModified', Types::STRING);
	}

	public function jsonSerialize(): array {
		return [
			'userId' => $this->userId,
			'fileId' => $this->fileId,
			'type' => $this->type,
			'name' => $this->name,
			'value' => self::conditional_json_decode($this->value),
			'content' => self::conditional_json_decode($this->content),
			'lastModified' => $this->lastModified,
		];
	}

	/**
	 * @psalm-return array{name: string, type: string, value: mixed, content: mixed, lastModified: string}
	 */
	public function toService(): array {
		return [
			'name' => $this->getName(),
			'type' => $this->getType(),
			'value' => self::conditional_json_decode($this->getValue()),
			'content' => self::conditional_json_decode($this->getContent()),
			'lastModified' => $this->getLastModified(),
		];
	}

	/**
	 * @param null|string $el
	 */
	public static function conditional_json_decode(string|null $el): mixed {
		if (empty($el)) {
			return $el;
		}
		$decoded = json_decode($el, true);
		return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $el;
	}
}
