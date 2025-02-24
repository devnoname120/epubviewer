<?php

declare(strict_types=1);

namespace OCA\Epubviewer\AppInfo;

use OCA\Epubviewer\Listener\BeforeTemplateRenderedListener;
use OCA\Epubviewer\Listener\FileNodeDeletedListener;
use OCA\Epubviewer\Listener\FilesLoadAdditionalScriptsListener;
use OCA\Epubviewer\Listener\PublicShareBeforeTemplateRenderedListener;
use OCA\Epubviewer\Listener\UserDeletedListener;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'epubviewer';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}


	public function register(IRegistrationContext $context): void {
		include_once __DIR__ . '/../../vendor/autoload.php';

		// “Emitted before the rendering step of each TemplateResponse. The event holds a flag that specifies if a user is logged in.”
		// See: https://docs.nextcloud.com/server/latest/developer_manual/basics/events.html#oca-settings-events-beforetemplaterenderedevent
		$context->registerEventListener(\OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class, BeforeTemplateRenderedListener::class);

		// “This event is triggered when the files app is rendered. It can be used to add additional scripts to the files app.”
		// See: https://docs.nextcloud.com/server/latest/developer_manual/basics/events.html#oca-files-event-loadadditionalscriptsevent
		$context->registerEventListener(\OCA\Files\Event\LoadAdditionalScriptsEvent::class, FilesLoadAdditionalScriptsListener::class);

		// “Emitted before the rendering step of the public share page happens. The event holds a flag that specifies if it is the authentication page of a public share.”
		// See: https://docs.nextcloud.com/server/latest/developer_manual/basics/events.html#oca-files-sharing-event-beforetemplaterenderedevent
		$context->registerEventListener(\OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent::class, PublicShareBeforeTemplateRenderedListener::class);

		$context->registerEventListener(\OCP\Files\Events\Node\NodeDeletedEvent::class, FileNodeDeletedListener::class);
		$context->registerEventListener(\OCP\User\Events\UserDeletedEvent::class, UserDeletedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
