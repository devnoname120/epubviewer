<?php

declare(strict_types=1);

namespace OCA\Epubviewer\AppInfo;

use OCA\Epubviewer\Listener\BeforeTemplateRenderedListener;
use OCA\Epubviewer\Listener\FileNodeDeletedListener;
use OCA\Epubviewer\Listener\LoadViewerListener;
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

		// Register services
		$context->registerService(\OCA\Epubviewer\Service\BookmarkService::class, function($c) {
			$userId = $c->get('UserId');
			if ($userId === null) {
				return null;
			}

			return new \OCA\Epubviewer\Service\BookmarkService(
				$c->get(\OCA\Epubviewer\Db\BookmarkMapper::class)
			);
		});

		$context->registerService(\OCA\Epubviewer\Service\PreferenceService::class, function($c) {
			$userId = $c->get('UserId');
			if ($userId === null) {
				return null;
			}

			return new \OCA\Epubviewer\Service\PreferenceService(
				$c->get(\OCA\Epubviewer\Db\PreferenceMapper::class)
			);
		});

		$this->registerPreviewProviders($context);

		// "Emitted before the rendering step of each TemplateResponse. The event holds a flag that specifies if a user is logged in."
		// See: https://docs.nextcloud.com/server/latest/developer_manual/basics/events.html#oca-settings-events-beforetemplaterenderedevent
		$context->registerEventListener(\OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class, BeforeTemplateRenderedListener::class);

		// Viewer pages (including public shares) dispatch this event before rendering the viewer app.
		$context->registerEventListener(\OCA\Viewer\Event\LoadViewer::class, LoadViewerListener::class);

		$context->registerEventListener(\OCP\Files\Events\Node\NodeDeletedEvent::class, FileNodeDeletedListener::class);
		$context->registerEventListener(\OCP\User\Events\UserDeletedEvent::class, UserDeletedListener::class);
	}

	private function registerPreviewProviders(IRegistrationContext $context): void {
		$context->registerPreviewProvider(\OCA\Epubviewer\Preview\EPubPreview::class, '/^application\/epub\+zip$/');
	}

	public function boot(IBootContext $context): void {
	}
}
