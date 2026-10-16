<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Service;

use OCA\Epubviewer\AppInfo\Application;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;

final class ReaderResponseBuilder implements IReaderResponseBuilder {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private ReaderTemplateContext $readerTemplateContext,
		private ?string $userId,
		private ?BookmarkService $bookmarkService,
		private ?PreferenceService $preferenceService,
	) {
	}

	/**
	 * @param array{fileName: string, fileType: string, fileId: int} $fileInfo
	 * @throws NotFoundException
	 */
	public function build(string $file, string $type, array $fileInfo): TemplateResponse {
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

		$template = $templates[$type] ?? null;
		if ($template === null) {
			throw new NotFoundException('Unsupported file type');
		}
		$scope = $template;

		$cursor = null;
		$defaults = null;
		$preferences = null;
		$annotations = null;

		if ($this->userId !== null && $this->bookmarkService !== null && $this->preferenceService !== null) {
			$cursor = $this->bookmarkService->getCursor($fileInfo['fileId']);
			$defaults = $this->preferenceService->getDefault($scope);
			$preferences = $this->preferenceService->get($scope, $fileInfo['fileId']);
			$annotations = $this->bookmarkService->get($fileInfo['fileId']);
		}

		$params = [
			'urlGenerator' => $this->urlGenerator,
			'downloadLink' => $file,
			'scope' => $scope,
			'fileId' => $fileInfo['fileId'],
			'fileName' => $fileInfo['fileName'],
			'fileType' => $fileInfo['fileType'],
			'cursor' => $cursor !== null && $cursor !== [] ? $this->toJson($cursor) : null,
			'defaults' => $defaults !== null && $defaults !== [] ? $this->toJson($defaults) : null,
			'preferences' => $preferences !== null && $preferences !== [] ? $this->toJson($preferences) : null,
			'annotations' => $annotations !== null && $annotations !== [] ? $this->toJson($annotations) : null,
			'appVersion' => $this->readerTemplateContext->getAppVersion(),
			'nonce' => $this->readerTemplateContext->getNonce(),
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
		if (in_array($type, ['application/x-cbr', 'application/comicbook+rar'], true)) {
			$policy->allowEvalWasm();
		}

		$response = new TemplateResponse(Application::APP_ID, $template, $params, TemplateResponse::RENDER_AS_BLANK);
		$response->setContentSecurityPolicy($policy);

		return $response;
	}

	private function toJson(array $value): string {
		return htmlspecialchars(json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8');
	}
}
