<?php

namespace OCA\Epubviewer\Controller;

use OCA\Epubviewer\Config;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class SettingsController extends Controller {

	/**
	 * @param string $appName
	 * @param IRequest $request
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		Config $config,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @brief set preference for file type association
	 *
	 * @NoAdminRequired
	 *
	 * @param string $EpubEnable
	 * @param string $PdfEnable
	 * @param string $CbxEnable
	 *
	 * @return array|JSONResponse
	 */
	public function setPreference(string $EpubEnable, string $PdfEnable, string $CbxEnable) {

		$l = OC::$server->getL10N('epubviewer');

		Config::set('epub_enable', $EpubEnable);
		Config::set('pdf_enable', $PdfEnable);
		Config::set('cbx_enable', $CbxEnable);

		$response = [
			'data' => ['message' => $l->t('Settings updated successfully.')],
			'status' => 'success'
		];

		return new JSONResponse($response);
	}
}
