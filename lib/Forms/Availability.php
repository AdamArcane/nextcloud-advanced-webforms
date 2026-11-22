<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

/**
 * Whether a published form takes responses right now, from its own limit and dates
 * (docs/SCHEMA.md section 3.1) and the server's cap (section 16). The public side refuses by it and
 * the owner's list shows it, so both always agree.
 */
final class Availability {
	public const OPEN = 'open';
	/** The opening time has not come yet. */
	public const SCHEDULED = 'scheduled';
	/** The closing time has passed. */
	public const ENDED = 'ended';
	/** The form has as many responses as it may take. */
	public const FULL = 'full';

	public const MAX_RESPONSES = 1000000;
	/** Unix seconds for the year 3000: a date field cannot go wrong by more than that. */
	public const MAX_TIME = 32503680000;

	/**
	 * @param array<string, mixed> $settings the published definition's `settings`
	 * @param int $responses how many responses count against the form's own limit (the ones on the
	 *                       waiting list do not, docs/SCHEMA.md section 3.2)
	 * @param int $policyCap the server's cap per form, 0 for none
	 * @param int|null $total how many responses the form has in all, which is what the server's cap counts.
	 *                        Without a waiting list it is the same as `$responses`
	 * @return array{state: string, opensAt: int|null, closesAt: int|null, cap: int, responses: int} `cap` is the
	 *                                                                                               lower of the form's and the server's limit, 0 for none
	 */
	public static function of(array $settings, int $now, int $responses, int $policyCap, ?int $total = null): array {
		$opensAt = self::time($settings['opensAt'] ?? null);
		$closesAt = self::time($settings['closesAt'] ?? null);
		$cap = self::cap($settings, $policyCap);
		$own = self::ownCap($settings);
		$total ??= $responses;
		$full = ($own > 0 && $responses >= $own) || ($policyCap > 0 && $total >= $policyCap);

		// A form that has ended stays ended, whatever else is true. Before that, "not open yet" tells a
		// respondent more than "full" does.
		$state = match (true) {
			$closesAt !== null && $now >= $closesAt => self::ENDED,
			$opensAt !== null && $now < $opensAt => self::SCHEDULED,
			$full => self::FULL,
			default => self::OPEN,
		};

		return ['state' => $state, 'opensAt' => $opensAt, 'closesAt' => $closesAt, 'cap' => $cap, 'responses' => $responses];
	}

	/**
	 * The form's own limit, 0 when it has none.
	 *
	 * @param array<string, mixed> $settings
	 */
	public static function ownCap(array $settings): int {
		$own = $settings['maxResponses'] ?? null;
		return is_int($own) && $own >= 1 && $own <= self::MAX_RESPONSES ? $own : 0;
	}

	/**
	 * Whether the form keeps taking responses at its limit and puts them on a waiting list
	 * (docs/SCHEMA.md section 3.2). It needs a limit of its own to mean anything.
	 *
	 * @param array<string, mixed> $settings
	 */
	public static function waitsWhenFull(array $settings): bool {
		return ($settings['whenFull'] ?? null) === 'waitlist' && self::ownCap($settings) > 0;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function cap(array $settings, int $policyCap): int {
		$own = self::ownCap($settings);
		$policyCap = max(0, $policyCap);
		if ($own === 0 || $policyCap === 0) {
			return max($own, $policyCap);
		}
		return min($own, $policyCap);
	}

	private static function time(mixed $value): ?int {
		return is_int($value) && $value >= 1 && $value <= self::MAX_TIME ? $value : null;
	}
}
