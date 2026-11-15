<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Settings;

/**
 * What the server policy says about a form's actions. Pure, so it is unit tested. Checked when a draft
 * is saved (as a warning), when a form is published (a refusal), and when an action is about to run.
 */
final class PolicyCheck {
	/**
	 * @param array<string, mixed> $definition
	 * @param list<string> $allowedTypes
	 * @return list<array{code: string, path: string}>
	 */
	public static function actionErrors(array $definition, array $allowedTypes, int $maxActions): array {
		$actions = is_array($definition['actions'] ?? null) ? $definition['actions'] : [];
		$errors = [];
		if (count($actions) > $maxActions) {
			$errors[] = ['code' => 'policy_too_many_actions', 'path' => 'actions'];
		}
		foreach ($actions as $index => $action) {
			if (is_array($action) && is_string($action['type'] ?? null) && !in_array($action['type'], $allowedTypes, true)) {
				$errors[] = ['code' => 'action_type_disabled', 'path' => 'actions[' . $index . ']'];
			}
		}
		return $errors;
	}

	/**
	 * Drop the actions of types the server no longer allows, so a form that is already live stops using
	 * them at once. Order and ids of the rest are kept.
	 *
	 * @param array<string, mixed> $definition
	 * @param list<string> $allowedTypes
	 * @return array<string, mixed>
	 */
	public static function withoutDisabledActions(array $definition, array $allowedTypes): array {
		if (!is_array($definition['actions'] ?? null)) {
			return $definition;
		}
		$definition['actions'] = array_values(array_filter(
			$definition['actions'],
			static fn (mixed $action): bool => is_array($action) && is_string($action['type'] ?? null) && in_array($action['type'], $allowedTypes, true),
		));
		return $definition;
	}

	/**
	 * The cut-off for retention: responses submitted before this moment are due for deletion.
	 * 0 days means retention is off and nothing is due, which is returned as null.
	 */
	public static function retentionCutoff(int $now, int $days): ?int {
		return $days <= 0 ? null : $now - $days * 86400;
	}
}
