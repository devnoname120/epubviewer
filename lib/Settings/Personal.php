<?php

namespace OCA\Epubviewer\Settings;

use OCA\Epubviewer\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Config\IUserConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {

	public function __construct(
		private string $userId,
		private IUserConfig $configManager,
		private IInitialState $initialState,
	) {
	}

	/**
	 * @return TemplateResponse returns the instance with all parameters set, ready to be rendered
	 * @since 9.1
	 */
	public function getForm() {
		$this->initialState->provideInitialState('personalSettings', [
			'epubEnabled' => $this->configManager->getValueString($this->userId, Application::APP_ID, 'epub_enable', 'true') === 'true',
			'pdfEnabled' => $this->configManager->getValueString($this->userId, Application::APP_ID, 'pdf_enable', 'false') === 'true',
			'cbxEnabled' => $this->configManager->getValueString($this->userId, Application::APP_ID, 'cbx_enable', 'true') === 'true',
		]);
		Util::addScript(Application::APP_ID, 'epubviewer-settings', 'core');
		Util::addStyle(Application::APP_ID, 'settings');
		Util::addStyle(Application::APP_ID, 'epubviewer-settings');

		return new TemplateResponse(Application::APP_ID, 'settings-personal', [], '');
	}

	/**
	 * @return string the section ID, e.g. 'sharing'
	 * @since 9.1
	 */
	public function getSection() {
		return 'epubviewer';
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 *             the admin section. The forms are arranged in ascending order of the
	 *             priority values. It is required to return a value between 0 and 100.
	 *
	 * E.g.: 70
	 * @since 9.1
	 */
	public function getPriority() {
		return 10;
	}
}
