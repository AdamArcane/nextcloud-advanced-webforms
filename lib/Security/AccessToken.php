<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Security;

/**
 * Proof that someone gave the right password for a form's link (docs/SCHEMA.md section 18.5). Stateless:
 * the form id, the moment it stops working and a fingerprint of the stored password hash are covered by
 * an HMAC. Changing or removing the password changes the fingerprint, so every token issued before
 * stops working at once. It is sent with each call instead of living in a cookie, because a form in a
 * frame on another website gets no cookies.
 */
final class AccessToken {
	public function __construct(
		private readonly string $secret,
		private readonly int $lifetimeSeconds = 43200,
	) {
	}

	public function issue(int $formId, string $passwordHash, int $now): string {
		$expires = $now + $this->lifetimeSeconds;
		return $expires . '.' . $this->signature($formId, $passwordHash, $expires);
	}

	public function check(string $token, int $formId, string $passwordHash, int $now): bool {
		if (preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/D', $token, $m) !== 1) {
			return false;
		}
		$expires = (int)$m[1];
		return hash_equals($this->signature($formId, $passwordHash, $expires), $m[2]) && $expires >= $now;
	}

	private function signature(int $formId, string $passwordHash, int $expires): string {
		return hash_hmac('sha256', 'access|' . $formId . '|' . $expires . '|' . hash('sha256', $passwordHash), $this->secret);
	}
}
