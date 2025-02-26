<?php

namespace OCA\Epubviewer\Service;

use OCA\Epubviewer\Db\Preference;
use OCA\Epubviewer\Db\PreferenceMapper;

/**
 * @template-extends Service<Preference>
 */
class PreferenceService extends Service {

	// (ab)use the fact that $fileId never goes below 1 by using the
	// value 0 to indicate a default preference
	public const DEFAULTS = 0;

	private PreferenceMapper $preferenceMapper;

	/**
	 * @param PreferenceMapper $preferenceMapper
	 */
	public function __construct(PreferenceMapper $preferenceMapper) {
		parent::__construct($preferenceMapper);
		$this->preferenceMapper = $preferenceMapper;
	}

	/**
	 * Get preferences for $scope+$fileId
	 *
	 * @param string $scope
	 * @param int $fileId
	 * @param string|null $name
	 * @return array
	 */
	public function get(string $scope, int $fileId, ?string $name = null) {
		$result = $this->preferenceMapper->get($scope, $fileId, $name);
		return array_map(
			function ($entity): array {
				return $entity->toService();
			}, $result);
	}

	/**
	 * @brief write preference
	 *
	 * scope identifies preference source, i.e. which renderer the preference applies to
	 * position type is format-dependent, e.g. CFI for epub, page number for CBR/CBZ, etc
	 *
	 * @param string $scope
	 * @param int $fileId
	 * @param string $name
	 * @param string $value
	 * @return Preference
	 */
	public function set($scope, $fileId, $name, $value): Preference {
		return $this->preferenceMapper->set($scope, $fileId, $name, $value);
	}

	/**
	 * @brief get default preference
	 *
	 * @param string $scope
	 * @param string $name
	 *
	 * @return array
	 */
	public function getDefault($scope, $name = null) {
		return $this->get($scope, static::DEFAULTS, $name);
	}

	/**
	 * @brief set default preference
	 *
	 * @param string $scope
	 * @param string $name
	 * @param string $value
	 * @return Preference
	 */
	public function setDefault($scope, $name, $value): Preference {
		return $this->preferenceMapper->set($scope, static::DEFAULTS, $name, $value);
	}

	/**
	 * @brief delete preference
	 *
	 * @param string $scope
	 * @param int $fileId
	 * @param string $name
	 */
	public function delete($scope, $fileId, $name): void {
		foreach ($this->preferenceMapper->get($scope, $fileId, $name) as $preference) {
			$this->preferenceMapper->delete($preference);
		}
	}

	/**
	 * @brief delete default
	 *
	 * @param string $scope
	 * @param string $name
	 */
	public function deleteDefault($scope, $name): void {
		$this->delete($scope, static::DEFAULTS, $name);
	}
}
