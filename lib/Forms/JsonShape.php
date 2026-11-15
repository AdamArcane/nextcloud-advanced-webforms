<?php

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Webforms\Forms;

/**
 * PHP decodes a JSON `{}` to an empty array, which encodes back to `[]`. The
 * format needs `{}` for its maps, so clients would see the wrong type for an
 * empty `settings` or `fields`. Call this wherever a definition is stored or
 * returned.
 */
final class JsonShape {
	/**
	 * @param array<string, mixed> $definition
	 * @return array<string, mixed>
	 */
	public static function definition(array $definition): array {
		foreach (['settings', 'fields'] as $key) {
			if (array_key_exists($key, $definition) && $definition[$key] === []) {
				$definition[$key] = new \stdClass();
			}
		}
		return $definition;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function map(array $data): array|\stdClass {
		return $data === [] ? new \stdClass() : $data;
	}
}
