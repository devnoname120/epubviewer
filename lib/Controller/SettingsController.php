<?php

namespace OCA\Epubviewer\Controller;

use OC;
use OCA\Epubviewer\Config;
use OCA\Epubviewer\Service\PreferenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class SettingsController extends Controller {
	private $urlGenerator;
	private $preferenceService;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IURLGenerator $urlGenerator
	 * @param PreferenceService $preferenceService
	 */
	public function __construct($appName,
		IRequest $request,
		IURLGenerator $urlGenerator,
		PreferenceService $preferenceService) {

		parent::__construct($appName, $request);
		$this->urlGenerator = $urlGenerator;
		$this->preferenceService = $preferenceService;
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
