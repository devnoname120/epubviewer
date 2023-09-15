<?php

/**
 * ownCloud - Epubviewer App
 *
 * @author Frank de Lange
 * @copyright 2014,2018 Frank de Lange
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 */

namespace OCA\Epubviewer\Controller;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\AppFramework\Http;
use OCP\AppFramework\Controller;
use OCA\Epubviewer\Service\PreferenceService;
use OCA\Epubviewer\Config;
use OCP\AppFramework\Http\JSONResponse;

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
                                PreferenceService $preferenceService ) {

		parent::__construct($appName, $request);
        $this->urlGenerator = $urlGenerator;
        $this->preferenceService = $preferenceService;
    }

	/**
     * @brief set preference for file type association
     *
     * @NoAdminRequired
     *
     * @param int $EpubEnable
     * @param int $EpubEnable
     * @param int $CbxEnable
     *
	 * @return array|\OCP\AppFramework\Http\JSONResponse
	 */
    public function setPreference(string $EpubEnable, string $PdfEnable, string $CbxEnable) {

		$l = \OC::$server->getL10N('epubviewer');

		Config::set('epub_enable', $EpubEnable);
		Config::set('pdf_enable', $PdfEnable);
		Config::set('cbx_enable', $CbxEnable);

		$response = array(
				'data' => array('message'=> $l->t('Settings updated successfully.')),
				'status' => 'success'
				);

		return new JSONResponse($response);
	}
}
