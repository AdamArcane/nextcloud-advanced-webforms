<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions;

/**
 * What the administrator set for mail that forms send (docs/SCHEMA.md section 16.2): where replies go
 * when an action does not say, the name mail is sent under, and whether form owners may set their own.
 * A plain value, so the runner that uses it needs no Nextcloud.
 */
final class MailPolicy {
	public const REPLY_NONE = 'none';
	public const REPLY_OWNER = 'owner';
	public const REPLY_ADDRESS = 'address';
	public const REPLY_MODES = [self::REPLY_NONE, self::REPLY_OWNER, self::REPLY_ADDRESS];

	public const MAX_NAME = 100;

	public function __construct(
		/** The default reply-to: none, the form owner's account email, or `replyToAddress`. */
		public readonly string $replyToMode = self::REPLY_NONE,
		public readonly string $replyToAddress = '',
		/** Whether an action's own reply-to and sender name are used. */
		public readonly bool $allowOverride = true,
		/** The default sender name. Empty leaves Nextcloud's own. */
		public readonly string $fromName = '',
	) {
	}

	/**
	 * A sender name as it may go into a mail header: one line, no control characters, not too long.
	 */
	public static function cleanName(string $name): string {
		$name = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
		return mb_substr($name, 0, self::MAX_NAME);
	}

	/**
	 * The action's own reply-to, if the policy lets it through. The answer to an email field always
	 * does: replying to the person who filled in the form is what a notification is for, and it names
	 * nobody the form owner chose.
	 *
	 * @param array<string, mixed> $action
	 */
	public function replyToSource(array $action): mixed {
		$source = $action['replyTo'] ?? null;
		if ($source === null || $this->allowOverride || self::isFieldSource($source)) {
			return $source;
		}
		return null;
	}

	/**
	 * The name the mail is sent under: the action's own when allowed, else the default, else null for
	 * Nextcloud's.
	 *
	 * @param array<string, mixed> $action
	 */
	public function fromNameFor(array $action): ?string {
		$own = $this->allowOverride && is_string($action['fromName'] ?? null) ? self::cleanName($action['fromName']) : '';
		$name = $own !== '' ? $own : self::cleanName($this->fromName);
		return $name === '' ? null : $name;
	}

	/** Whether a reply-to is written as `{ "field": "<id>" }`. */
	public static function isFieldSource(mixed $source): bool {
		return is_array($source) && array_keys($source) === ['field'];
	}

	/**
	 * What an email action sets that the policy does not let through, for the builder to say so.
	 *
	 * @param array<string, mixed> $action
	 * @return list<string> the property names
	 */
	public function lockedProperties(array $action): array {
		if ($this->allowOverride) {
			return [];
		}
		$locked = [];
		if (array_key_exists('replyTo', $action) && !self::isFieldSource($action['replyTo'])) {
			$locked[] = 'replyTo';
		}
		if (array_key_exists('fromName', $action)) {
			$locked[] = 'fromName';
		}
		return $locked;
	}
}
