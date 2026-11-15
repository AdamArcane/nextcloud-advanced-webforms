<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions;

use OCA\Webforms\Rules\RuleEngine;

/**
 * Decides, for one submission, what the respondent is told and which
 * background actions to queue (docs/SCHEMA.md sections 7.1 and 7.4).
 *
 * `message` and `redirect` share one slot and the first match wins, because the
 * respondent needs the answer in the submit response. `email`, `webhook`, `tables` and `files` are
 * returned as ids to queue, never run here.
 */
final class ActionPlanner {
	public function __construct(
		private readonly RuleEngine $engine,
	) {
	}

	/**
	 * @param array<string, mixed> $definition the version the entry was submitted against
	 * @param array<string, mixed> $data the entry's answers (visible fields only)
	 * @return array{message: string|null, redirect: string|null, queue: list<string>}
	 */
	public function plan(array $definition, array $data): array {
		/** @var array<string, array<string, mixed>> $fields */
		$fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
		$actions = is_array($definition['actions'] ?? null) ? $definition['actions'] : [];

		$message = null;
		$redirect = null;
		$slotTaken = false;
		$queue = [];

		foreach ($actions as $action) {
			if (!is_array($action) || !is_string($action['id'] ?? null) || !is_string($action['type'] ?? null)) {
				continue;
			}
			if (array_key_exists('when', $action) && !$this->engine->matchesCondition($fields, $action['when'], $data)) {
				continue;
			}

			switch ($action['type']) {
				case 'message':
					if (!$slotTaken && is_string($action['text'] ?? null)) {
						$message = Template::render($action['text'], $fields, $data, Template::TEXT);
						$slotTaken = true;
					}
					break;
				case 'redirect':
					if (!$slotTaken && is_string($action['url'] ?? null)) {
						$url = Template::render($action['url'], $fields, $data, Template::URL);
						// Defence in depth: whatever the template produced must still be a plain https URL.
						if (self::isSafeRedirect($url)) {
							$redirect = $url;
							$slotTaken = true;
						}
					}
					break;
				case 'email':
				case 'webhook':
				case 'tables':
				case 'files':
					$queue[] = $action['id'];
					break;
			}
		}

		return ['message' => $message, 'redirect' => $redirect, 'queue' => $queue];
	}

	public static function isSafeRedirect(string $url): bool {
		return strlen($url) <= 2000
			&& preg_match('~^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?([/?#][^\s\x00-\x1F]*)?$~D', $url) === 1;
	}
}
