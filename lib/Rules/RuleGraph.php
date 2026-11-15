<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Rules;

/**
 * Dependency order of fields implied by rules (docs/SCHEMA.md section 6.4):
 * a rule's targets depend on the fields its condition reads.
 */
final class RuleGraph {
	/**
	 * Field IDs read by a condition tree, in order of appearance.
	 *
	 * @param mixed $node
	 * @return list<string>
	 */
	public static function sourcesOf(mixed $node): array {
		if (!is_array($node)) {
			return [];
		}
		$sources = [];
		foreach (['all', 'any'] as $key) {
			if (isset($node[$key]) && is_array($node[$key])) {
				foreach ($node[$key] as $member) {
					foreach (self::sourcesOf($member) as $id) {
						$sources[] = $id;
					}
				}
			}
		}
		if (isset($node['field']) && is_string($node['field'])) {
			$sources[] = $node['field'];
		}
		return $sources;
	}

	/**
	 * Field IDs ordered so that every field comes after the fields its
	 * visibility and required state depend on. Ties keep document order.
	 *
	 * @param list<string> $fieldIds in document order
	 * @param list<array<string, mixed>> $rules
	 * @return list<string>
	 * @throws RuleCycleException
	 */
	public static function order(array $fieldIds, array $rules): array {
		$known = array_fill_keys($fieldIds, true);

		/** @var array<string, array<string, true>> $dependsOn */
		$dependsOn = [];
		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				continue;
			}
			$sources = self::sourcesOf($rule['when'] ?? null);
			$targets = is_array($rule['targets'] ?? null) ? $rule['targets'] : [];
			foreach ($targets as $target) {
				if (!is_string($target) || !isset($known[$target])) {
					continue;
				}
				foreach ($sources as $source) {
					if (isset($known[$source])) {
						$dependsOn[$target][$source] = true;
					}
				}
			}
		}

		$order = [];
		$placed = [];
		$remaining = $fieldIds;
		while ($remaining !== []) {
			$progress = false;
			$next = [];
			foreach ($remaining as $id) {
				$ready = true;
				foreach (array_keys($dependsOn[$id] ?? []) as $source) {
					if (!isset($placed[$source])) {
						$ready = false;
						break;
					}
				}
				if ($ready) {
					$placed[$id] = true;
					$order[] = $id;
					$progress = true;
				} else {
					$next[] = $id;
				}
			}
			if (!$progress) {
				throw new RuleCycleException('Rules depend on each other in a loop: ' . implode(', ', $next));
			}
			$remaining = $next;
		}

		return $order;
	}
}
