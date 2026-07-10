<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Controller;

use OCA\Epubviewer\Service\PublicSharePath;
use OCA\Epubviewer\Service\ReaderResponseBuilder;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\PublicShareController;
use OCP\Constants;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\ISession;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use function pathinfo;

class PublicReaderController extends PublicShareController {
	private ?IShare $share = null;

	public function __construct(
		string $appName,
		IRequest $request,
		ISession $session,
		private IManager $shareManager,
		private PublicSharePath $publicSharePath,
		private ReaderResponseBuilder $readerResponseBuilder,
	) {
		parent::__construct($appName, $request, $session);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	public function showReader(string $token, string $file, string $type): TemplateResponse {
		$parsedPath = $this->publicSharePath->parse($file);
		if ($parsedPath === null || $parsedPath['token'] !== $token || $token !== $this->getToken()) {
			throw new NotFoundException('Share token mismatch');
		}

		$node = $this->getShare()->getNode();
		foreach ($parsedPath['segments'] as $segment) {
			if (!$node instanceof Folder) {
				throw new NotFoundException('Shared file path not found');
			}

			$node = $node->get($segment);
		}

		if ($node->getType() === FileInfo::TYPE_FOLDER) {
			throw new NotFoundException('Shared file path or name not set');
		}

		return $this->readerResponseBuilder->build($file, $type, $this->getFileInfo($node));
	}

	public function isValidToken(): bool {
		$this->share = null;

		try {
			$share = $this->shareManager->getShareByToken($this->getToken());
		} catch (ShareNotFound $e) {
			return false;
		}

		if (($share->getPermissions() & Constants::PERMISSION_READ) === 0 || !$share->getNode()->isReadable()) {
			return false;
		}

		$this->share = $share;
		return true;
	}

	protected function getPasswordHash(): ?string {
		return $this->getShare()->getPassword();
	}

	protected function isPasswordProtected(): bool {
		return $this->getShare()->getPassword() !== null;
	}

	private function getShare(): IShare {
		if ($this->share === null) {
			throw new NotFoundException('Share not found');
		}

		return $this->share;
	}

	/**
	 * @return array{fileName: string, fileType: string, fileId: int}
	 */
	private function getFileInfo(Node $node): array {
		$path = $node->getPath();

		return [
			'fileName' => pathinfo($path, PATHINFO_FILENAME),
			'fileType' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
			'fileId' => $node->getId(),
		];
	}
}
