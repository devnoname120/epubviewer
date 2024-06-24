<?php

namespace OCA\Epubviewer\Utility;

class Time {
	public function getTime() {
		return time();
	}

	/**
	 * @return string the current unix time in milliseconds
	 */
	public function getMicroTime(): string {
		[$millisecs, $secs] = explode(" ", microtime());
		return $secs . substr($millisecs, 2, 6);
	}

}
