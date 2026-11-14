<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Security;

/**
 * Signed "form was rendered at" token (docs/SCHEMA.md section 10, step 2).
 * A submission that arrives too soon after rendering, or long after, is
 * rejected. Cheap bot defence, not a captcha. Stateless: the form id, version
 * id, and timestamp are covered by an HMAC.
 */
final class FillTimeToken {
	public function __construct(
		private readonly string $secret,
		private readonly int $minAgeSeconds = 3,
		private readonly int $maxAgeSeconds = 86400,
	) {
	}

	public function issue(int $formId, int $versionId, int $now): string {
		return $now . '.' . $this->signature($formId, $versionId, $now);
	}

	/**
	 * @return 'ok'|'invalid'|'too_fast'|'expired'
	 */
	public function check(string $token, int $formId, int $versionId, int $now): string {
		if (preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/D', $token, $m) !== 1) {
			return 'invalid';
		}
		$issuedAt = (int)$m[1];
		if (!hash_equals($this->signature($formId, $versionId, $issuedAt), $m[2])) {
			return 'invalid';
		}
		$age = $now - $issuedAt;
		if ($age < $this->minAgeSeconds) {
			return 'too_fast';
		}
		return $age > $this->maxAgeSeconds ? 'expired' : 'ok';
	}

	private function signature(int $formId, int $versionId, int $issuedAt): string {
		return hash_hmac('sha256', $formId . '|' . $versionId . '|' . $issuedAt, $this->secret);
	}
}
