<?php

namespace OCA\Epubviewer\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class Personal implements ISettings {

	public function __construct(
		private string $userId,
		private IConfig $configManager,
	) {
	}

	/**
	 * @return TemplateResponse returns the instance with all parameters set, ready to be rendered
	 * @since 9.1
	 */
	public function getForm() {

		$parameters = [
			'EpubEnable' => $this->configManager->getUserValue($this->userId, 'epubviewer', 'epub_enable', 'true'),
			'PdfEnable' => $this->configManager->getUserValue($this->userId, 'epubviewer', 'pdf_enable', 'false'),
			'CbxEnable' => $this->configManager->getUserValue($this->userId, 'epubviewer', 'cbx_enable', 'true'),
		];
		return new TemplateResponse('epubviewer', 'settings-personal', $parameters, '');
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
