<?php

namespace OCA\Epubviewer\Controller;

use OCA\Epubviewer\Service\PreferenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class PreferenceController extends Controller {

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param ?PreferenceService $preferenceService
	 */
	public function __construct($appName,
		IRequest $request,
		private ?PreferenceService $preferenceService,
		private ?string $userId) {

		parent::__construct($appName, $request);
	}

	/**
	 * @param string $scope
	 * @param int $fileId
	 * @param string $name if null, return all preferences for $scope + $fileId
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function get(string $scope, int $fileId, string $name): array|JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([]);
		}
		return $this->preferenceService->get($scope, $fileId, $name);
	}

	/**
	 * @brief write preference for $fileId
	 *
	 * @param string $scope
	 * @param int $fileId
	 * @param string $name
	 * @param string $value
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function set(string $scope, int $fileId, string $name, string $value): void {
		if ($this->userId === null) {
			return;
		}
		$this->preferenceService->set($scope, $fileId, $name, $value);
	}


	/**
	 * @brief return default preference
	 *
	 * @param string $scope
	 * @param string $name if null, return all default preferences for scope
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getDefault(string $scope, string $name): array|JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([]);
		}
		return $this->preferenceService->getDefault($scope, $name);
	}

	/**
	 * @brief write default preference
	 *
	 * @param string $scope
	 * @param string $name
	 * @param string $value
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setDefault(string $scope, string $name, string $value): void {
		if ($this->userId === null) {
			return;
		}

		$this->preferenceService->setDefault($scope, $name, $value);
	}

	/**
	 * @brief delete preference
	 *
	 * @param string $scope
	 * @param int $fileId
	 * @param string $name
	 */
	public function delete(string $scope, int $fileId, string $name): void {
		if ($this->userId === null) {
			return;
		}
		$this->preferenceService->delete($scope, $fileId, $name);
	}

	/**
	 * @brief delete default preference
	 *
	 * @param $scope
	 * @param $name
	 */
	public function deleteDefault(string $scope, string $name): void {
		if ($this->userId === null) {
			return;
		}
		$this->preferenceService->deleteDefault($scope, $name);
	}
}