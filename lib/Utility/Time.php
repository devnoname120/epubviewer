<?php

namespace OCA\Epubviewer\Utility;

class Time {
	public function getTime() {
		return time();
	}

	/**
	 *
	 * @return int the current unix time in milliseconds
	 */
	public function getMicroTime() {
		[$millisecs, $secs] = explode(" ", microtime());
		return $secs . substr($millisecs, 2, 6);
	}

}
