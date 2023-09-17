<?php

/**
 * ownCloud - Epubviewer App
 *
 * @author Frank de Lange
 * @copyright 2015 - 2017 Frank de Lange
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 */

namespace OCA\Epubviewer\AppInfo;

use OCP\AppFramework\App;
use OCP\Util;

// Register the composer autoloader for packages shipped by this app, if applicable
include_once __DIR__ . '/../vendor/autoload.php';

$l = \OC::$server->getL10N('epubviewer');

\OCA\Epubviewer\Hooks::register();
Util::addscript('epubviewer', 'plugin');
