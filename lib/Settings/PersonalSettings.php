<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Settings;

use OCA\Webforms\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/** Each user's own preferences for the app, under Personal settings. */
class PersonalSettings implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-personal');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-personal');
		return new TemplateResponse(Application::APP_ID, 'personal', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
