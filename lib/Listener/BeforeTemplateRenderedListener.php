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
use OCP\Util;
use Psr\Container\ContainerInterface;

/** @template-implements IEventListener<BeforeTemplateRenderedEvent> */
class BeforeTemplateRenderedListener implements IEventListener
{
    private IInitialState $initialState;
    private IUserSession $userSession;
    private IConfig $config;

    public function __construct(
        IInitialState $initialState,
        IUserSession  $userSession,
        IConfig       $config
    )
    {
        $this->initialState = $initialState;
        $this->userSession = $userSession;
        $this->config = $config;
    }

    public function handle(Event $event): void
    {
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
    }
}
