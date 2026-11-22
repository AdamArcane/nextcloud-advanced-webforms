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
	/** A response that counts, and the default (docs/SCHEMA.md section 7.1). */
	public const ON_CONFIRMED = 'confirmed';
	/** A response that went on the form's waiting list. */
	public const ON_WAITLISTED = 'waitlisted';
	/** A response the owner moved off the waiting list. It has no respondent to answer. */
	public const ON_PROMOTED = 'promoted';
	/** The values an action's `on` may have. */
	public const ON_VALUES = [self::ON_CONFIRMED, self::ON_WAITLISTED, self::ON_PROMOTED];

	public function __construct(
		private readonly RuleEngine $engine,
	) {
	}

	/**
	 * @param array<string, mixed> $definition the version the entry was submitted against
	 * @param array<string, mixed> $data the entry's answers (visible fields only)
	 * @param string $on which kind of response this is, one of the `ON_*` values. An action without `on`
	 *                   runs for a submitted response of either kind, and never for a promotion
	 * @return array{message: string|null, redirect: string|null, queue: list<string>}
	 */
	public function plan(array $definition, array $data, string $on = self::ON_CONFIRMED): array {
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
			$actionOn = $action['on'] ?? null;
			if ($actionOn === null ? $on === self::ON_PROMOTED : $actionOn !== $on) {
				continue;
			}
			if (array_key_exists('when', $action) && !$this->engine->matchesCondition($fields, $action['when'], $data)) {
				continue;
			}
			// The respondent's answer comes from `message` and `redirect`. A promotion has no respondent to
			// answer, and a waitlisted one is told it is on the list unless the action is for that case.
			$answers = $on === self::ON_CONFIRMED || ($on === self::ON_WAITLISTED && $actionOn === self::ON_WAITLISTED);

			switch ($action['type']) {
				case 'message':
					if ($answers && !$slotTaken && is_string($action['text'] ?? null)) {
						$message = Template::render($action['text'], $fields, $data, Template::TEXT);
						$slotTaken = true;
					}
					break;
				case 'redirect':
					if ($answers && !$slotTaken && is_string($action['url'] ?? null)) {
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
