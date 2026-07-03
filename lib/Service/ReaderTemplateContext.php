<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Service;

use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCA\Epubviewer\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Server;

class ReaderTemplateContext {
	public function __construct(
		private IAppManager $appManager,
	) {
	}

	public function getAppVersion(): string {
		return $this->appManager->getAppVersion(Application::APP_ID);
	}

	public function getNonce(): string {
		// Nextcloud 33/34 do not expose a public nonce manager interface.
		/** @var ContentSecurityPolicyNonceManager $nonceManager */
		$nonceManager = Server::get(ContentSecurityPolicyNonceManager::class);

		return $nonceManager->getNonce();
	}
}
