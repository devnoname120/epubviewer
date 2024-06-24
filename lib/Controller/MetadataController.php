<?php

namespace OCA\Epubviewer\Controller;

use OCA\Epubviewer\Service\MetadataService;
use OCP\AppFramework\Controller;
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
	 * @NoAdminRequired
	 *
	 * @param int $fileId
	 * @param string $value
	 *
	 * @return array|JSONResponse
	 */
	public function setAll($fileId, $value) {
		return $this->metadataService->setAll($fileId, $value);
	}

	/**
	 * @brief return metadata item
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param int $fileId
	 * @param string $name
	 *
	 * @return array|JSONResponse
	 */
	public function get($fileId, $name) {
		return $this->metadataService->get($fileId, $name);
	}

	/**
	 * @brief write metadata item
	 *
	 * @NoAdminRequired
	 *
	 * @param int $fileId
	 * @param string $name
	 * @param string $value
	 *
	 * @return array|JSONResponse
	 */
	public function set($fileId, $name, $value) {
		return $this->metadataService->set($fileId, $name, $value);
	}

}
