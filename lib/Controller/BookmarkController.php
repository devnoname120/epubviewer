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
	 * @param BookmarkService $bookmarkService
	 */
	public function __construct($appName,
		IRequest $request,
		private BookmarkService $bookmarkService) {

		parent::__construct($appName, $request);
	}

	/**
	 * @brief return bookmark
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
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
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @param int $fileId
	 * @param string $name
	 * @param string $value
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function set(int $fileId, string $name, string $value, ?string $type = null, ?string $content = null): \OCA\Epubviewer\Db\Bookmark {
		return $this->bookmarkService->set($fileId, $name, $value, $type, $content);
	}


	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getCursor($fileId): array|null {
		return $this->bookmarkService->getCursor($fileId);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setCursor($fileId, $value): \OCA\Epubviewer\Db\Bookmark {
		return $this->bookmarkService->setCursor($fileId, $value);
	}

	/**
	 * @brief delete bookmark
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
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
	public function deleteCursor($fileId): void {
		$this->bookmarkService->deleteCursor($fileId);
	}
}
