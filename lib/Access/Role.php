<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Access;

/**
 * What a person may do with a form (docs/SCHEMA.md section 22.1). A higher role may do everything the
 * lower ones may, so a call states the lowest role it needs and anyone at or above it passes.
 */
enum Role: int {
	case REVIEWER = 1;
	case EDITOR = 2;
	case OWNER = 3;

	public function atLeast(self $needs): bool {
		return $this->value >= $needs->value;
	}

	/** The name used in the API and the share table. */
	public function key(): string {
		return match ($this) {
			self::REVIEWER => 'reviewer',
			self::EDITOR => 'editor',
			self::OWNER => 'owner',
		};
	}

	/** A role someone can be given by sharing: never the owner, who is the form's own. */
	public static function forShare(string $key): ?self {
		return match ($key) {
			'reviewer' => self::REVIEWER,
			'editor' => self::EDITOR,
			default => null,
		};
	}

	/**
	 * The role a person has on a form: the owner is the owner, anyone else holds the highest role among the
	 * shares that reach them, directly or through a group (docs/SCHEMA.md section 22.3).
	 *
	 * @param list<string> $shareRoles the `role` of every share of this form that reaches the person
	 */
	public static function resolve(bool $isOwner, array $shareRoles): ?self {
		if ($isOwner) {
			return self::OWNER;
		}
		$role = null;
		foreach ($shareRoles as $key) {
			$role = self::highest($role, self::forShare($key));
		}
		return $role;
	}

	public static function highest(?self $a, ?self $b): ?self {
		if ($a === null || $b === null) {
			return $a ?? $b;
		}
		return $a->value >= $b->value ? $a : $b;
	}
}
