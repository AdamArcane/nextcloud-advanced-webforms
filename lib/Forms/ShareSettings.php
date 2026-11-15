<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

/**
 * The rules for a form's sharing options (docs/SCHEMA.md section 18): what a custom address may look
 * like, and which origins may be named as allowed to frame the form. Origins end up in a
 * Content-Security-Policy header, so nothing passes here that could add to that header.
 */
final class ShareSettings {
	public const MAX_ORIGINS = 20;

	/** Words that are, or may become, part of this app's own addresses. */
	private const RESERVED = ['api', 'captcha', 'embed', 'new', 'admin', 'settings'];

	private const SLUG = '/^[a-z0-9](?:[a-z0-9-]{1,62})[a-z0-9]$/D';
	private const ORIGIN = '/^https?:\/\/(?:\*\.)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*(?::[0-9]{1,5})?$/D';

	/**
	 * Why this custom address cannot be used, or null when it can: 3 to 64 lowercase letters, digits
	 * and hyphens, not starting or ending with a hyphen, no two hyphens in a row, and not a reserved
	 * word. Whether another form already has it is the caller's question.
	 */
	public static function slugError(string $slug): ?string {
		if (preg_match(self::SLUG, $slug) !== 1 || str_contains($slug, '--')) {
			return 'slug_invalid';
		}
		if (in_array($slug, self::RESERVED, true)) {
			return 'slug_reserved';
		}
		return null;
	}

	/**
	 * The origins as they are stored: lowercase, without a trailing slash, no duplicates.
	 *
	 * @param array<mixed> $origins
	 * @return list<string>|null null when one is not an origin (scheme, host, optional port) or there are too many
	 */
	public static function normalizeOrigins(array $origins): ?array {
		$result = [];
		foreach ($origins as $origin) {
			if (!is_string($origin)) {
				return null;
			}
			$origin = rtrim(strtolower(trim($origin)), '/');
			if ($origin === '') {
				continue;
			}
			if (strlen($origin) > 255 || preg_match(self::ORIGIN, $origin) !== 1) {
				return null;
			}
			$result[$origin] = true;
		}
		if (count($result) > self::MAX_ORIGINS) {
			return null;
		}
		return array_keys($result);
	}

	/**
	 * Stored origins, read back. Anything that would not pass today is left out.
	 *
	 * @return list<string>
	 */
	public static function decodeOrigins(?string $stored): array {
		$decoded = json_decode((string)$stored, true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_filter($decoded, static fn (mixed $origin): bool => is_string($origin) && preg_match(self::ORIGIN, $origin) === 1));
	}
}
