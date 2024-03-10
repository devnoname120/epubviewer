<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Listener;

use OCA\Epubviewer\Config;
use OCP\AppFramework\Services\IInitialState;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Epubviewer\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

class FilesLoadAdditionalScriptsListener implements IEventListener
{
    public function __construct(
        IInitialState $initialState
    )
    {
        $this->initialState = $initialState;
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof LoadAdditionalScriptsEvent)) {
            return;
        }

        // addInitScript was added in Nextcloud 28
        if (method_exists(Util::class, 'addInitScript')) {
            Util::addInitScript(Application::APP_ID, 'epubviewer-main');
            // TODO: remove me once we drop support for Nextcloud 27 and below
        } else {
            Util::addScript(Application::APP_ID, 'epubviewer-main');
        }

        Util::addStyle(Application::APP_ID, 'epubviewer-main');
    }
}
