<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Uploads\Adapter;

use OCA\AdvancedForms\Uploads\Ports\TokenPort;
use OCP\Security\ISecureRandom;

final class NextcloudTokens implements TokenPort {
	public function __construct(
		private readonly ISecureRandom $random,
	) {
	}

	public function newToken(): string {
		return $this->random->generate(32, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
	}
}
