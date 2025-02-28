<?php

namespace OCA\Epubviewer;

use OCA\Epubviewer\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * Config class for Reader
 */
class Config {
	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private IUserSession $userSession
	) {
	}

	/**
	 * @brief get user config value
	 *
	 * @param string $key value to retrieve
	 * @param string $default default value to use
	 * @return string retrieved value or default
	 */
	public function getUserValue(string $key, string $default = ''): string {
		$user = $this->userSession->getUser();
		if (!$user) {
			return $default;
		}
		return $this->config->getUserValue($user->getUID(), Application::APP_ID, $key, $default);
	}

	/**
	 * @brief set user config value
	 *
	 * @param string $key key for value to change
	 * @param string $value value to use
	 */
	public function setUserValue(string $key, string $value): void {
		$user = $this->userSession->getUser();
		if (!$user) {
			return;
		}
		$this->config->setUserValue($user->getUID(), Application::APP_ID, $key, $value);
	}

	/**
	 * @brief get app config value
	 *
	 * @param string $key value to retrieve
	 * @param string $default default value to use
	 * @return string retrieved value or default
	 */
	public function getApp(string $key, string $default): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
	}

	/**
	 * @brief set app config value
	 *
	 * @param string $key key for value to change
	 * @param string $value value to use
	 */
	public function setApp(string $key, string $value): void {
		$this->appConfig->setValueString(Application::APP_ID, $key, $value);
	}
}
