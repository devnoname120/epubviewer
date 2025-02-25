<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Listener;

use OCA\Epubviewer\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<\OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent> */
class PublicShareBeforeTemplateRenderedListener implements IEventListener {

	public function __construct() {
	}

	public function handle(Event $event): void {
		Util::addInitScript(Application::APP_ID, 'epubviewer-public');
	}
}
