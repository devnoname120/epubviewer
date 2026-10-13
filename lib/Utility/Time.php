<?php

namespace OCA\Epubviewer\Utility;

final class Time {
	/**
	 * @psalm-return int<1, max>
	 */
	public function getTime(): int {
		return time();
	}

	/**
	 * @return string the current unix time in milliseconds
	 */
	public function getMicroTime(): string {
		[$millisecs, $secs] = explode(' ', microtime());
		return $secs . substr($millisecs, 2, 6);
	}

}
