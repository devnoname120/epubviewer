<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Controller;

use OCA\Epubviewer\Service\BookmarkService;
use OCA\Epubviewer\Service\PreferenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use function pathinfo;

class PageController extends Controller {

	/** @var IURLGenerator */
	private IURLGenerator $urlGenerator;
	/** @var IRootFolder */
	private IRootFolder $rootFolder;
	private IManager $shareManager;
	private ?string $userId;
	private BookmarkService $bookmarkService;
	private PreferenceService $preferenceService;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IURLGenerator $urlGenerator
	 * @param IRootFolder $rootFolder
	 * @param IManager $shareManager
	 * @param ?string $userId
	 * @param BookmarkService $bookmarkService
	 * @param PreferenceService $preferenceService
	 */
	public function __construct(
		$appName,
		IRequest $request,
		IURLGenerator $urlGenerator,
		IRootFolder $rootFolder,
		IManager $shareManager,
		$userId,
		BookmarkService $bookmarkService,
		PreferenceService $preferenceService,
	) {
		parent::__construct($appName, $request);
		$this->urlGenerator = $urlGenerator;
		$this->rootFolder = $rootFolder;
		$this->shareManager = $shareManager;
		$this->userId = $userId;
		$this->bookmarkService = $bookmarkService;
		$this->preferenceService = $preferenceService;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 */
	public function showReader(): TemplateResponse {
		$params = [];
		$file = $this->request->getParam('file');
		$type = $this->request->getParam('type');

		if (!$file || !$type) {
			throw new NotFoundException('file or type missing from request params');
		}

		$fileInfo = $this->getFileInfo($file);
		$templates = [
			'application/epub+zip' => 'epubviewer',
			'application/pdf' => 'pdfreader',
			'application/x-cbr' => 'cbreader',
			'application/x-cbz' => 'cbreader',
			'application/comicbook+zip' => 'cbreader',
			'application/comicbook+rar' => 'cbreader',
			'application/comicbook+tar' => 'cbreader',
			'application/comicbook+7z' => 'cbreader',
			'application/comicbook+ace' => 'cbreader',
			'application/comicbook+truecrypt' => 'cbreader',
		];

		$scope = $template = $templates[$type];
		$cursor = $this->bookmarkService->getCursor($fileInfo['fileId']);
		
		$params = [
			'urlGenerator' => $this->urlGenerator,
			'downloadLink' => $file,
			'scope' => $scope,
			'fileId' => $fileInfo['fileId'],
			'fileName' => $fileInfo['fileName'],
			'fileType' => $fileInfo['fileType'],
			'cursor' => $cursor ? $this->toJson($cursor) : null,
			'defaults' => $this->toJson($this->preferenceService->getDefault($scope)),
			'preferences' => $this->toJson($this->preferenceService->get($scope, (int)$fileInfo['fileId'])),
			'annotations' => $this->toJson($this->bookmarkService->get((int)$fileInfo['fileId']))
		];

		$policy = new ContentSecurityPolicy();
		$policy->addAllowedStyleDomain('\'self\'');
		$policy->addAllowedStyleDomain('blob:');
		$policy->addAllowedScriptDomain('\'self\'');
		$policy->addAllowedFrameDomain('\'self\'');
		$policy->addAllowedFontDomain('\'self\'');
		$policy->addAllowedFontDomain('data:');
		$policy->addAllowedFontDomain('blob:');
		$policy->addAllowedImageDomain('blob:');
		$policy->addAllowedWorkerSrcDomain('\'self\'');

		$response = new TemplateResponse($this->appName, $template, $params, 'blank');
		$response->setContentSecurityPolicy($policy);

		return $response;
	}

	/**
	 * @brief sharing-aware file info retriever
	 *
	 * @param string $path path-fragment from url
	 * @return array{fileName: string, fileType: string, fileId: int}
	 * @throws NotFoundException
	 */
	private function getFileInfo($path) {
		// Anonymous users can access shared files
		$count = 0;
		$shareToken = preg_replace("/(?:\/index\.php)?\/s\/([A-Za-z0-9_\-+]{3,32})\/download.*/", '$1', $path, 1, $count);
		if ($count === 1) {
			/* shared file or directory */
			$node = $this->shareManager->getShareByToken($shareToken)->getNode();
			$type = $node->getType();

			/* shared directory, need file path to continue, */
			if ($type == FileInfo::TYPE_FOLDER) {
				$query = [];
				parse_str(parse_url($path, PHP_URL_QUERY), $query);
				if (isset($query['path']) && isset($query['files'])) {
					$node = $node->get($query['path'])->get($query['files']);
				} else {
					throw new NotFoundException('Shared file path or name not set');
				}
			}
			$filePath = $node->getPath();
			$fileId = $node->getId();
		} else {
			// For user files, we need a logged in user
			if (!$this->userId) {
				throw new NotFoundException('User not found');
			}
			
			$filePath = $path;
			$userFolder = $this->rootFolder->getUserFolder($this->userId);
			$fileId = $userFolder->get(preg_replace("/.*\/remote.php\/dav\/files\/[^\/]*\/(.*)/", '$1', rawurldecode($path)))->getId();
		}

		$filename = pathinfo($filePath, PATHINFO_FILENAME);
		$extension = pathinfo($filePath, PATHINFO_EXTENSION);

		/** @var array{fileName: string, fileType: string, fileId: int} */
		$result = [
			'fileName' => $filename,
			'fileType' => strtolower($extension),
			'fileId' => (int)$fileId
		];

		return $result;
	}

	private function toJson(array $value): string {
		return htmlspecialchars(json_encode($value), ENT_QUOTES, 'UTF-8');
	}

	public function load(): JSONResponse {
		if (!$this->userId) {
			return new JSONResponse(['success' => false, 'error' => 'User not found']);
		}

		$file = $this->request->getParam('file');
		if ($file) {
			$userFolder = $this->rootFolder->getUserFolder($this->userId);
			$node = $userFolder->get(preg_replace("/.*\/remote.php\/dav\/files\/[^\/]*\/(.*)/", '$1', rawurldecode($file)));
			return new JSONResponse(['success' => true, 'node' => $node]);
		}
		return new JSONResponse(['success' => false]);
	}
}
