<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Settings;

use OCA\Webforms\AppInfo\Application;
use OCP\Config\IUserConfig;

/** Per-user preferences, kept in the user's own config so they go when the account does. */
class UserPreferences {
	private const OPEN_PUBLISHED_ON_RESPONSES = 'open_published_on_responses';

	public function __construct(
		private readonly IUserConfig $config,
	) {
	}

	/** @return array{openPublishedOnResponses: bool} */
	public function forUser(string $userId): array {
		return [
			'openPublishedOnResponses' => $this->config->getValueBool($userId, Application::APP_ID, self::OPEN_PUBLISHED_ON_RESPONSES, true),
		];
	}

	public function setOpenPublishedOnResponses(string $userId, bool $value): void {
		$this->config->setValueBool($userId, Application::APP_ID, self::OPEN_PUBLISHED_ON_RESPONSES, $value);
	}
}
