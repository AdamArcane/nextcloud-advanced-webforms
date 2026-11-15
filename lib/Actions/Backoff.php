<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions;

/**
 * When to try a failed action again (docs/SCHEMA.md section 7.4): five attempts
 * in all, spread over about ten hours.
 */
final class Backoff {
	/** Seconds to wait after the 1st, 2nd, 3rd and 4th failed attempt. */
	private const DELAYS = [60, 300, 1800, 7200];

	public const MAX_ATTEMPTS = 5;

	/**
	 * @param int $attemptsMade how many attempts have failed so far (at least 1)
	 * @return int|null seconds until the next attempt, or null to give up
	 */
	public static function delayAfter(int $attemptsMade): ?int {
		return self::DELAYS[$attemptsMade - 1] ?? null;
	}
}
