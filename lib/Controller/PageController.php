<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Controller;

use OCA\Epubviewer\Service\PublicSharePath;
use OCA\Epubviewer\Service\ReaderResponseBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use function pathinfo;

class PageController extends Controller {
	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IRootFolder $rootFolder
	 * @param ?string $userId
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private PublicSharePath $publicSharePath,
		private ReaderResponseBuilder $readerResponseBuilder,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	public function showReader(): TemplateResponse {
		$file = $this->request->getParam('file');
		$type = $this->request->getParam('type');

		if (!$file || !$type) {
			throw new NotFoundException('file or type missing from request params');
		}

		$fileInfo = $this->getFileInfo($file);
		return $this->readerResponseBuilder->build($file, $type, $fileInfo);
	}

	/**
	 * @brief authenticated-user file info retriever
	 *
	 * @param string $path path-fragment from url
	 * @return array{fileName: string, fileType: string, fileId: int}
	 * @throws NotFoundException
	 */
	private function getFileInfo(string $path): array {
		if ($this->publicSharePath->parse($path) !== null) {
			throw new NotFoundException('Public shares must use the public reader route');
		}

		if ($this->userId === null || empty($this->userId)) {
			throw new NotFoundException('User not found');
		}

		$filePath = $this->extractUserRelativePath($path);
		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		$fileId = $userFolder->get($filePath)->getId();

		$filename = pathinfo($filePath, PATHINFO_FILENAME);
		$extension = pathinfo($filePath, PATHINFO_EXTENSION);

		/** @var array{fileName: string, fileType: string, fileId: int} */
		$result = [
			'fileName' => $filename,
			'fileType' => strtolower($extension),
			'fileId' => $fileId
		];

		return $result;
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

}
