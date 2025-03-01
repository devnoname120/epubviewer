<?php

namespace OCA\Epubviewer\Controller;

use OCA\Epubviewer\Config;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

class SettingsController extends Controller {
	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param Config $config
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private Config $config,
		private IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @brief set preference for file type association
	 */
	#[NoAdminRequired]
	public function setPreference(string $EpubEnable, string $PdfEnable, string $CbxEnable): array|JSONResponse {

		$this->config->setUserValue('epub_enable', $EpubEnable);
		$this->config->setUserValue('pdf_enable', $PdfEnable);
		$this->config->setUserValue('cbx_enable', $CbxEnable);

		$response = [
			'data' => ['message' => $this->l10n->t('Settings updated successfully.')],
			'status' => 'success'
		];

		return new JSONResponse($response);
	}
}
