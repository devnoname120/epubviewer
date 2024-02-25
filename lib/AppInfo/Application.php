<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: devnoname120 <devnoname120@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Epubviewer\AppInfo;

use OCA\Epubviewer\Hooks;
use OCA\Epubviewer\Listener\LoadAdditionalListener;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\Util;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'epubviewer';

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }


    public function register(IRegistrationContext $context): void
    {
        /*
         * For further information about the app bootstrapping, please refer to our documentation:
         * https://docs.nextcloud.com/server/latest/developer_manual/app_development/bootstrap.html
         */

        // Register the composer autoloader for packages shipped by this app, if applicable
        include_once __DIR__ . '/../../vendor/autoload.php';

        $context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadAdditionalListener::class);

        $l = \OC::$server->getL10N('epubviewer');
//    Hooks::register();
    }

    public function boot(IBootContext $context): void
    {
    }
}
