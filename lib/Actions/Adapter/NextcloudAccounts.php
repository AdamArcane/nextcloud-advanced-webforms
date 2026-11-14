<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Actions\Adapter;

use OCA\AdvancedForms\Actions\Ports\AccountPort;
use OCP\IUserManager;

final class NextcloudAccounts implements AccountPort {
	public function __construct(
		private readonly IUserManager $users,
	) {
	}

	public function emailOf(string $userId): ?string {
		$address = $this->users->get($userId)?->getSystemEMailAddress();
		return is_string($address) && $address !== '' ? $address : null;
	}
}
