<?php

declare(strict_types=1);

namespace OCA\Epubviewer\AppInfo;

use OC;
use OCA\Epubviewer\Listener\BeforeTemplateRenderedListener;
use OCA\Epubviewer\Listener\PublicShareBeforeTemplateRenderedListener;
use OCA\Epubviewer\Listener\FilesLoadAdditionalScriptsListener;
use OCA\Epubviewer\Preview\EPubPreview;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
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
        include_once __DIR__ . '/../../vendor/autoload.php';

        $this->registerProvider($context);

        // “Emitted before the rendering step of each TemplateResponse. The event holds a flag that specifies if a user is logged in.”
        // See: https://docs.nextcloud.com/server/latest/developer_manual/basics/events.html#oca-settings-events-beforetemplaterenderedevent
        $context->registerEventListener(BeforeTemplateRenderedEvent::class, BeforeTemplateRenderedListener::class);

        // “This event is triggered when the files app is rendered. It can be used to add additional scripts to the files app.”
        // See: https://docs.nextcloud.com/server/latest/developer_manual/basics/events.html#oca-files-event-loadadditionalscriptsevent
        $context->registerEventListener(LoadAdditionalScriptsEvent::class, FilesLoadAdditionalScriptsListener::class);

        // TODO: handle \OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent
        /// “Emitted before the rendering step of the public share page happens. The event holds a flag that specifies if it is the authentication page of a public share.”
        // See: https://docs.nextcloud.com/server/latest/developer_manual/basics/events.html#oca-files-sharing-event-beforetemplaterenderedevent
        $context->registerEventListener(\OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent::class, PublicShareBeforeTemplateRenderedListener::class);

        $l = OC::$server->getL10N('epubviewer');
//    Hooks::register();
    }

    private function registerProvider(IRegistrationContext $context)
    {
        $context->registerPreviewProvider(EPubPreview::class, '/^application\/epub\+zip$/');
    }

    public function boot(IBootContext $context): void
    {
    }
}
