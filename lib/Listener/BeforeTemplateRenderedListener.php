<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Listener;

use OCA\Epubviewer\AppInfo\Application;
use OCA\Epubviewer\Config;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/** @template-implements IEventListener<BeforeTemplateRenderedEvent> */
class BeforeTemplateRenderedListener implements IEventListener {
	private IInitialState $initialState;
    private IUserSession $userSession;
    private IConfig $config;

    public function __construct(
		IInitialState $initialState,
        IUserSession $userSession,
        IConfig $config
    ) {
		$this->initialState = $initialState;
        $this->userSession = $userSession;
        $this->config = $config;
    }

	public function handle(Event $event): void {

        /** @var BeforeTemplateRenderedEvent $event */
        if ($event->getResponse()->getRenderAs() === TemplateResponse::RENDER_AS_USER) {
            $this->initialState->provideLazyInitialState('enableEpub', function () {
                if ($this->userSession->getUser()) {
                    $uid = $this->userSession->getUser()->getUID();
                    return $this->config->getUserValue($uid, Application::APP_ID, 'epub_enable', 'true') === 'true';
                }
                return false;
            });

            $this->initialState->provideLazyInitialState('enablePdf', function () {
                if ($this->userSession->getUser()) {
                    $uid = $this->userSession->getUser()->getUID();
                    return $this->config->getUserValue($uid, Application::APP_ID, 'pdf_enable', 'false') === 'true';
                }
                return false;
            });

            $this->initialState->provideLazyInitialState('enableCbx', function () {
                if ($this->userSession->getUser()) {
                    $uid = $this->userSession->getUser()->getUID();
                    return $this->config->getUserValue($uid, Application::APP_ID, 'cbx_enable', 'true') === 'true';
                }
                return false;
            });
        }

//        if ($user instanceof IUser) {
//            $userId = $user->getUID();
//
//            $this->initialState->provideLazyInitialState(
//                'enableEpub',
//                $this->config->getUserValue($userId, Application::APP_ID, 'epub_enable', 'true'),
//            );
//
//            /** User background */
//            $this->initialState->provideInitialState(
//                'backgroundImage',
//                $this->config->getUserValue($userId, Application::APP_ID, 'background_image', BackgroundService::BACKGROUND_DEFAULT),
//            );
//
//            /** User color */
//            $this->initialState->provideInitialState(
//                'backgroundColor',
//                $this->config->getUserValue($userId, Application::APP_ID, 'background_color', BackgroundService::DEFAULT_COLOR),
//            );
//
//            /**
//             * Admin background. `backgroundColor` if disabled,
//             * mime type if defined and empty by default
//             */
//            $this->initialState->provideInitialState(
//                'themingDefaultBackground',
//                $this->config->getAppValue('theming', 'backgroundMime', ''),
//            );
//            $this->initialState->provideInitialState(
//                'defaultShippedBackground',
//                BackgroundService::DEFAULT_BACKGROUND_IMAGE,
//            );
//
//            /** List of all shipped backgrounds */
//            $this->initialState->provideInitialState(
//                'shippedBackgrounds',
//                BackgroundService::SHIPPED_BACKGROUNDS,
//            );
//        }
	}
}
