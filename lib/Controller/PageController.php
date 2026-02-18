<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Controller;

use OCA\Epubviewer\Service\BookmarkService;
use OCA\Epubviewer\Service\PreferenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Constants;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Share\Exceptions\ShareNotFound;
use function pathinfo;

class PageController extends Controller {

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
		private IURLGenerator $urlGenerator,
		private IRootFolder $rootFolder,
		private IManager $shareManager,
		private ?string $userId,
		private ?BookmarkService $bookmarkService,
		private ?PreferenceService $preferenceService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[PublicPage]
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


		$cursor = null;
		$defaults = null;
		$preferences = null;
		$annotations = null;

		if ($this->userId !== null) {
			$cursor = $this->bookmarkService->getCursor($fileInfo['fileId']);
			$defaults  = $this->preferenceService->getDefault($scope);
			$preferences = $this->preferenceService->get($scope, (int)$fileInfo['fileId']);
			$annotations = $this->bookmarkService->get((int)$fileInfo['fileId']);
		}
		
		$params = [
			'urlGenerator' => $this->urlGenerator,
			'downloadLink' => $file,
			'scope' => $scope,
			'fileId' => $fileInfo['fileId'],
			'fileName' => $fileInfo['fileName'],
			'fileType' => $fileInfo['fileType'],
			'cursor' => $cursor ? $this->toJson($cursor) : null,
			'defaults' => $defaults ? $this->toJson($defaults) : null,
			'preferences' => $preferences ? $this->toJson($preferences) : null,
			'annotations' => $annotations ? $this->toJson($annotations) : null
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
	private function getFileInfo(string $path): array {
		$sharedFileInfo = $this->resolveSharedFileInfo($path);

		if ($sharedFileInfo !== null) {
			[$filePath, $fileId] = $sharedFileInfo;
		} else {
			// For user files, we need a logged in user
			if ($this->userId === null || empty($this->userId)) {
				throw new NotFoundException('User not found');
			}

			$filePath = $this->extractUserRelativePath($path);
			$userFolder = $this->rootFolder->getUserFolder($this->userId);
			$fileId = $userFolder->get($filePath)->getId();
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

	/**
	 * Resolve public share file information from public DAV URLs.
	 *
	 * @param string $path
	 * @return array{0: string, 1: int}|null
	 * @throws NotFoundException
	 */
	private function resolveSharedFileInfo(string $path): ?array {
		$segments = $this->splitPathSegments($path);
		$start = $this->findPathSequence($segments, ['public.php', 'dav', 'files']);
		if ($start === null) {
			return null;
		}

		$token = $segments[$start + 3] ?? '';
		if ($token === '') {
			throw new NotFoundException('Share token missing');
		}

		$share = $this->getReadableShareByToken($token);
		$node = $share->getNode();
		$fileSegments = array_slice($segments, $start + 4);
		foreach ($fileSegments as $segment) {
			$node = $node->get($segment);
		}

		if ($node->getType() === FileInfo::TYPE_FOLDER) {
			throw new NotFoundException('Shared file path or name not set');
		}

		return [$node->getPath(), (int)$node->getId()];
	}

	/**
	 * @param string $path
	 * @return string
	 * @throws NotFoundException
	 */
	private function extractUserRelativePath(string $path): string {
		$segments = $this->splitPathSegments($path);
		$start = $this->findPathSequence($segments, ['remote.php', 'dav', 'files']);

		if ($start !== null) {
			// /remote.php/dav/files/{userId}/{relative/path}
			$segments = array_slice($segments, $start + 4);
		}

		$relativePath = implode('/', $segments);
		if ($relativePath === '') {
			throw new NotFoundException('File path not set');
		}

		return $relativePath;
	}

	/**
	 * @param string $value
	 * @return string[]
	 */
	private function splitPathSegments(string $value): array {
		$path = (string)(parse_url($value, PHP_URL_PATH) ?? $value);
		$path = trim($path, '/');
		if ($path === '') {
			return [];
		}

		return array_map(static fn (string $segment): string => rawurldecode($segment), explode('/', $path));
	}

	/**
	 * @param string[] $segments
	 * @param string[] $sequence
	 * @return int|null
	 */
	private function findPathSequence(array $segments, array $sequence): ?int {
		$sequence = array_values($sequence);
		$sequenceLength = count($sequence);
		$limit = count($segments) - $sequenceLength;
		if ($limit < 0) {
			return null;
		}

		for ($start = 0; $start <= $limit; $start++) {
			$matches = true;
			for ($offset = 0; $offset < $sequenceLength; $offset++) {
				if ($segments[$start + $offset] !== $sequence[$offset]) {
					$matches = false;
					break;
				}
			}

			if ($matches) {
				return $start;
			}
		}

		return null;
	}

	/**
	 * @param string $token
	 * @return IShare
	 * @throws NotFoundException
	 */
	private function getReadableShareByToken(string $token): IShare {
		try {
			$share = $this->shareManager->getShareByToken($token);
		} catch (ShareNotFound $e) {
			throw new NotFoundException('Share not found');
		}

		if (($share->getPermissions() & Constants::PERMISSION_READ) === 0 || !$share->getNode()->isReadable()) {
			throw new NotFoundException('Share not readable');
		}

		return $share;
	}

	private function toJson(array $value): string {
		return htmlspecialchars(json_encode($value), ENT_QUOTES, 'UTF-8');
	}

	public function load(): JSONResponse {
		if ($this->userId === null || empty($this->userId)) {
			return new JSONResponse(['success' => false, 'error' => 'User not found']);
		}

		$file = $this->request->getParam('file');
		if ($file) {
			$userFolder = $this->rootFolder->getUserFolder($this->userId);
			$node = $userFolder->get($this->extractUserRelativePath($file));
			return new JSONResponse(['success' => true, 'node' => $node]);
		}
		return new JSONResponse(['success' => false]);
	}
}
