<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Security\Captcha;

/** Where the administrator's captcha choice is kept. */
interface ConfigStore {
	public function get(string $key, bool $sensitive = false): string;

	public function set(string $key, string $value, bool $sensitive = false): void;
}
