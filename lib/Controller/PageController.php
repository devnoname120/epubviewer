<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Controller;

use OCA\Epubviewer\Service\BookmarkService;
use OCA\Epubviewer\Service\MetadataService;
use OCA\Epubviewer\Service\PreferenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Share\IManager;

class PageController extends Controller {

	/** @var IURLGenerator */
	private IURLGenerator $urlGenerator;
	/** @var IRootFolder */
	private IRootFolder $rootFolder;
	private IManager $shareManager;
	private ?string $userId;
	private BookmarkService $bookmarkService;
	private MetadataService $metadataService;
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
	 * @param MetadataService $metadataService
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
		MetadataService $metadataService) {
		parent::__construct($appName, $request);
		$this->urlGenerator = $urlGenerator;
		$this->rootFolder = $rootFolder;
		$this->shareManager = $shareManager;
		$this->userId = $userId;
		$this->bookmarkService = $bookmarkService;
		$this->metadataService = $metadataService;
		$this->preferenceService = $preferenceService;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 */
	public function showReader(): TemplateResponse {
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

		/**
		 *  $fileInfo = [
		 *      fileId => null,
		 *      fileName => null,
		 *      fileType => null
		 *  ];
		 */
		$fileInfo = $this->getFileInfo($this->request->get['file']);

		$fileId = $fileInfo['fileId'];
		$type = $this->request->get["type"];
		$scope = $template = $templates[$type];
		$cursor = $this->bookmarkService->getCursor($fileId);

		$params = [
			'urlGenerator' => $this->urlGenerator,
			'downloadLink' => $this->request->get['file'],
			'scope' => $scope,
			'fileId' => $fileInfo['fileId'],
			'fileName' => $fileInfo['fileName'],
			'fileType' => $fileInfo['fileType'],
			'cursor' => $cursor ? $this->toJson($cursor) : null,
			'defaults' => $this->toJson($this->preferenceService->getDefault($scope)),
			'preferences' => $this->toJson($this->preferenceService->get($scope, $fileId)),
			'metadata' => $this->toJson($this->metadataService->get($fileId)),
			'annotations' => $this->toJson($this->bookmarkService->get($fileId))
		];

		$policy = new ContentSecurityPolicy();
		$policy->addAllowedStyleDomain('\'self\'');
		$policy->addAllowedStyleDomain('blob:');
		$policy->addAllowedScriptDomain('\'self\'');
		$policy->addAllowedFrameDomain('\'self\'');
		$policy->addAllowedChildSrcDomain('\'self\'');
		$policy->addAllowedFontDomain('\'self\'');
		$policy->addAllowedFontDomain('data:');
		$policy->addAllowedFontDomain('blob:');
		$policy->addAllowedImageDomain('blob:');

		$response = new TemplateResponse($this->appName, $template, $params, 'blank');
		$response->setContentSecurityPolicy($policy);

		return $response;
	}

	/**
	 * @brief sharing-aware file info retriever
	 *
	 * Work around the differences between normal and shared file access
	 * (this should be abstracted away in OC/NC IMnsHO)
	 *
	 * @param string $path path-fragment from url
	 * @return array
	 * @throws NotFoundException
	 */
	private function getFileInfo($path) {
		$count = 0;
		$shareToken = preg_replace("/(?:\/index\.php)?\/s\/([A-Za-z0-9_\-+]{3,32})\/download.*/", "$1", $path, 1, $count);

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
			$filePath = $path;
			$fileId = $this->rootFolder->getUserFolder($this->userId)
				->get(preg_replace("/.*\/remote.php\/dav\/files\/[^\/]*\/(.*)/", "$1", rawurldecode($this->request->get['file'])))
				->getId();
		}

		return [
			'fileName' => pathInfo($filePath, PATHINFO_FILENAME),
			'fileType' => strtolower(pathInfo($filePath, PATHINFO_EXTENSION)),
			'fileId' => $fileId
		];
	}

	private function toJson(array $value): string {
		return htmlspecialchars(json_encode($value), ENT_QUOTES, 'UTF-8');
	}
}
