<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Listener;

use OCA\Epubviewer\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IUserSession;

/** @template-implements IEventListener<\OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent> */
class BeforeTemplateRenderedListener implements IEventListener {
	public function __construct(
		private IInitialState $initialState,
		private IUserSession $userSession,
		private IConfig $config,
	) {
	}

	public function handle(Event $event): void {
		/** @var \OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent $event */
		if ($event->getResponse()->getRenderAs() === TemplateResponse::RENDER_AS_USER) {
			$this->initialState->provideLazyInitialState('enableEpub', function () {
				$user = $this->userSession->getUser();
				if ($user !== null) {
					$uid = $user->getUID();
					return $this->config->getUserValue($uid, Application::APP_ID, 'epub_enable', 'true') === 'true';
				}
				return true;
			});

			$this->initialState->provideLazyInitialState('enablePdf', function () {
				$user = $this->userSession->getUser();
				if ($user !== null) {
					$uid = $user->getUID();
					return $this->config->getUserValue($uid, Application::APP_ID, 'pdf_enable', 'false') === 'true';
				}
				return false;
			});

			$this->initialState->provideLazyInitialState('enableCbx', function () {
				$user = $this->userSession->getUser();
				if ($user !== null) {
					$uid = $user->getUID();
					return $this->config->getUserValue($uid, Application::APP_ID, 'cbx_enable', 'true') === 'true';
				}
				return true;
			});
		}
	}
}
