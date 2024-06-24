<?php

declare(strict_types=1);

require_once './vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->ignoreVCSIgnored(true)
	->exclude('config')
	->exclude('data')
	->notPath('composer')
	->notPath('node_modules')
	->notPath('vendor')
	->notPath('build')
	->notPath('screenshots')
	->notPath('l10n')
	->notPath('src')
	->notPath('css')
	->notPath('js')
	->in(__DIR__);
return $config;
