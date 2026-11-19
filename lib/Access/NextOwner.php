<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Access;

/**
 * Who a form passes to when its owner's account is deleted (docs/SCHEMA.md section 22.7): the editor who was
 * added first, among those shared with by account (not through a group) who may be an owner.
 */
final class NextOwner {
	/**
	 * @param list<array{type: int, with: string, role: string}> $shares in the order they were added
	 * @param callable(string): bool $mayOwn whether the account exists and may create forms
	 */
	public static function pick(array $shares, int $accountType, callable $mayOwn): ?string {
		foreach ($shares as $share) {
			if ($share['type'] === $accountType && $share['role'] === Role::EDITOR->key() && $mayOwn($share['with'])) {
				return $share['with'];
			}
		}
		return null;
	}
}
