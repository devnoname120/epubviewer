<?php

namespace OCA\Epubviewer\Controller;

use OCA\Epubviewer\Service\MetadataService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;

use OCP\IRequest;

class MetadataController extends Controller {

	private $metadataService;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param MetadataService $metadataService
	 */
	public function __construct($appName,
		IRequest $request,
		MetadataService $metadataService) {

		parent::__construct($appName, $request);
		$this->metadataService = $metadataService;
	}


	/**
	 * @brief write metadata
	 *
	 * @param int $fileId
	 * @param string $value
	 *
	 * @return array|JSONResponse
	 */
	#[NoAdminRequired]
	public function setAll($fileId, $value) {
		return $this->metadataService->setAll($fileId, $value);
	}

	/**
	 * @brief return metadata item
	 *
	 * @param int $fileId
	 * @param string $name
	 *
	 * @return array|JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function get($fileId, $name) {
		return $this->metadataService->get($fileId, $name);
	}

	/**
	 * @brief write metadata item
	 *
	 * @param int $fileId
	 * @param string $name
	 * @param string $value
	 *
	 * @return array|JSONResponse
	 */
	#[NoAdminRequired]
	public function set($fileId, $name, $value) {
		return $this->metadataService->set($fileId, $name, $value);
	}

}
