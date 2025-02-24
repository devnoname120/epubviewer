<?php


namespace OCA\Epubviewer\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

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
 * @method int getLastModified()
 * @method void setLastModified(int $lastModified)
 */
class Preference extends Entity implements JsonSerializable {

	protected $userId;  // user for whom this preference is valid
	protected $scope;   // scope (default or specific renderer)
	protected $fileId;  // file for which this preference is set
	protected $name;    // preference name
	protected $value;   // preference value
	protected $lastModified;    // modification timestamp

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('scope', 'string');
		$this->addType('fileId', 'integer');
		$this->addType('name', 'string');
		$this->addType('value', 'string');
		$this->addType('lastModified', 'integer');
	}

	protected static function conditional_json_decode($value) {
		if (empty($value)) {
			return $value;
		}
		$decoded = json_decode($value, true);
		return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
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
			'value' => $this->conditional_json_decode($this->getValue()),
		];
	}
}
