<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Security\Captcha;

use OCA\Webforms\AppInfo\Application;
use OCP\IAppConfig;

final class AppConfigStore implements ConfigStore {
	public function __construct(
		private readonly IAppConfig $config,
	) {
	}

	public function get(string $key, bool $sensitive = false): string {
		return $this->config->getValueString(Application::APP_ID, $key, '', $sensitive);
	}

	public function set(string $key, string $value, bool $sensitive = false): void {
		// A sensitive value is stored encrypted, and Nextcloud hides it from the config listings.
		$this->config->setValueString(Application::APP_ID, $key, $value, $sensitive, $sensitive);
	}
}
