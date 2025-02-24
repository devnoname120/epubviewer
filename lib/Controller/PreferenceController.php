<?php

namespace OCA\Epubviewer\Controller;

use OCA\Epubviewer\Service\PreferenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class PreferenceController extends Controller {

	private $preferenceService;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param PreferenceService $preferenceService
	 */
	public function __construct($appName,
		IRequest $request,
		PreferenceService $preferenceService) {

		parent::__construct($appName, $request);
		$this->preferenceService = $preferenceService;
	}

	/**
	 * @brief return preference for $fileId
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $scope
	 * @param int $fileId
	 * @param string $name if null, return all preferences for $scope + $fileId
	 *
	 * @return array|JSONResponse
	 */
	public function get($scope, $fileId, $name) {
		return $this->preferenceService->get($scope, $fileId, $name);
	}

	/**
	 * @brief write preference for $fileId
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $scope
	 * @param int $fileId
	 * @param string $name
	 * @param string $value
	 *
	 * @return array|JSONResponse
	 */
	public function set($scope, $fileId, $name, $value) {
		return $this->preferenceService->set($scope, $fileId, $name, $value);
	}


	/**
	 * @brief return default preference
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $scope
	 * @param string $name if null, return all default preferences for scope
	 *
	 * @return array|JSONResponse
	 */
	public function getDefault($scope, $name) {
		return $this->preferenceService->getDefault($scope, $name);
	}

	/**
	 * @brief write default preference
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $scope
	 * @param string $name
	 * @param string $value
	 *
	 * @return array|JSONResponse
	 */
	public function setDefault($scope, $name, $value) {
		return $this->preferenceService->setDefault($scope, $name, $value);
	}

	/**
	 * @brief delete preference
	 *
	 * @param string $scope
	 * @param int $fileId
	 * @param string $name
	 */
	public function delete($scope, $fileId, $name): void {
		$this->preferenceService->delete($scope, $fileId, $name);
	}

	/**
	 * @brief delete default preference
	 *
	 * @param $scope
	 * @param $name
	 */
	public function deleteDefault($scope, $name): void {
		$this->preferenceService->deleteDefault($scope, $name);
	}
}
