<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions\Ports;

/**
 * Looks up Nextcloud accounts, so a recipient can be "the form owner" or
 * "the signed-in respondent" without copying an address into the form.
 */
interface AccountPort {
	/**
	 * The account's current email address, or null when the account does not exist or has none set.
	 */
	public function emailOf(string $userId): ?string;
}
