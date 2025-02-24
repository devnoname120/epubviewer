<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Listener;

use OCA\Epubviewer\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Util;

/** @template-implements IEventListener<BeforeTemplateRenderedEvent> */
class PublicShareBeforeTemplateRenderedListener implements IEventListener {
	private IInitialState $initialState;
	private IConfig $config;

	public function __construct(
		IInitialState $initialState,
		IConfig       $config
	) {
		$this->initialState = $initialState;
		$this->config = $config;
	}

	public function handle(Event $event): void {
		Util::addInitScript(Application::APP_ID, 'epubviewer-public');
	}
}
