<?php

namespace OCA\Epubviewer\Controller;

use OCA\Epubviewer\Service\BookmarkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;


use OCP\IRequest;

class BookmarkController extends Controller {

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param ?BookmarkService $bookmarkService
	 */
	public function __construct($appName,
		IRequest $request,
		private ?BookmarkService $bookmarkService) {

		parent::__construct($appName, $request);
	}

	/**
	 * @brief return bookmark
	 *
	 * @param int $fileId
	 * @param string $name
	 *
	 * @return array|JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function get(int $fileId, string $name, ?string $type = null): array|JSONResponse {
		return $this->bookmarkService->get($fileId, $name, $type);
	}

	/**
	 * @brief write bookmark
	 *
	 * @param int $fileId
	 * @param string $name
	 * @param string $value
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function set(int $fileId, string $name, string $value, ?string $type = null, ?string $content = null): void {
		$this->bookmarkService->set($fileId, $name, $value, $type, $content);
	}

	/**
	 * @brief return cursor for $fileId
	 *
	 * @param int $fileId
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getCursor(int $fileId): array|null {
		return $this->bookmarkService->getCursor($fileId);
	}



	/**
	 * @brief write cursor for $fileId
	 *
	 * @param int $fileId
	 * @param string $value
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setCursor(int $fileId, string $value): void {
		$this->bookmarkService->setCursor($fileId, $value);
	}

	/**
	 * @brief delete bookmark
	 *
	 * @param int $fileId
	 * @param string name
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function delete(int $fileId, string $name): void {
		$this->bookmarkService->delete($fileId, $name);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function deleteCursor(int $fileId): void {
		$this->bookmarkService->deleteCursor($fileId);
	}
}
