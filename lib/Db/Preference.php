<?php

namespace OCA\Epubviewer\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getValue()
 * @method void setValue(string $value)
 * @method string getLastModified()
 * @method void setLastModified(string $lastModified)
 */
class Preference extends Entity implements JsonSerializable {

	protected $userId;  // user for whom this preference is valid
	protected $scope;   // scope (default or specific renderer)
	protected $fileId;  // file for which this preference is set
	protected $name;    // preference name
	protected $value;   // preference value
	protected $lastModified;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('scope', Types::STRING);
		$this->addType('fileId', Types::INTEGER);
		$this->addType('name', Types::STRING);
		$this->addType('value', Types::STRING);
		$this->addType('lastModified', Types::STRING);
	}

	public static function conditional_json_decode(string $el): mixed {
		if (empty($el)) {
			return $el;
		}
		$decoded = json_decode($el, true);
		return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $el;
	}

	public function jsonSerialize(): array {
		return [
			'scope' => $this->scope,
			'fileId' => $this->fileId,
			'name' => $this->name,
			'value' => self::conditional_json_decode($this->value),
			'lastModified' => $this->lastModified,
		];
	}

	/**
	 * @psalm-return array{name: mixed, value: mixed}
	 */
	public function toService(): array {
		return [
			'name' => $this->getName(),
			'value' => self::conditional_json_decode($this->getValue()),
		];
	}
}
