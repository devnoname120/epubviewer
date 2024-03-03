<?php


namespace OCA\Epubviewer\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class Preference extends ReaderEntity implements JsonSerializable
{

    protected $userId;  // user for whom this preference is valid
    protected $scope;   // scope (default or specific renderer)
    protected $fileId;  // file for which this preference is set
    protected $name;    // preference name
    protected $value;   // preference value
    protected $lastModified;    // modification timestamp

    public function jsonSerialize()
    {
        return [
            'id' => $this->getId(),
            'scope' => $this->getScope(),
            'fileId' => $this->getFileId(),
            'name' => $this->getName(),
            'value' => $this->conditional_json_decode($this->getValue()),
            'lastModified' => $this->getLastModified(),
        ];
    }

    public function toService()
    {
        return [
            'name' => $this->getName(),
            'value' => $this->conditional_json_decode($this->getValue()),
        ];
    }
}

