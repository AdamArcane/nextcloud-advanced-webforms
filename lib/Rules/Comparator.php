<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Rules;

/**
 * Evaluates one leaf condition. The TypeScript twin is src/rules/engine.ts.
 * Semantics: docs/SCHEMA.md section 6.2.
 */
final class Comparator {
	/**
	 * @param string|float|bool|list<string>|null $normalized the source field's normalized value, null when empty
	 * @param mixed $ruleValue the rule's raw `value`
	 */
	public static function compare(string $type, string $op, string|float|bool|array|null $normalized, mixed $ruleValue): bool {
		$empty = $normalized === null;

		switch ($op) {
			case 'empty':
				return $empty;
			case 'not_empty':
				return !$empty;
			case 'neq':
				return $empty || !self::equals($type, $normalized, $ruleValue);
			case 'not_contains':
				return $empty || !self::contains($type, $normalized, $ruleValue);
			case 'not_in':
				return $empty || !self::in($type, $normalized, $ruleValue);
			case 'contains_none':
				return $empty || !self::containsAny($normalized, $ruleValue);
		}

		// Every remaining operator is false against an empty value.
		if ($empty) {
			return false;
		}

		return match ($op) {
			'eq' => self::equals($type, $normalized, $ruleValue),
			'contains' => self::contains($type, $normalized, $ruleValue),
			'in' => self::in($type, $normalized, $ruleValue),
			'contains_any' => self::containsAny($normalized, $ruleValue),
			'contains_all' => self::containsAll($normalized, $ruleValue),
			'gt', 'gte', 'lt', 'lte' => self::ordered($type, $op, $normalized, $ruleValue),
			default => false,
		};
	}

	private static function equals(string $type, mixed $normalized, mixed $ruleValue): bool {
		$expected = ValueNormalizer::normalize($type, $ruleValue);
		return $expected !== null && $expected === $normalized;
	}

	private static function contains(string $type, mixed $normalized, mixed $ruleValue): bool {
		if ($type === FieldType::CHECKBOXES) {
			return is_array($normalized) && is_string($ruleValue) && in_array($ruleValue, $normalized, true);
		}
		$needle = ValueNormalizer::normalize($type, $ruleValue);
		return is_string($normalized) && is_string($needle) && str_contains($normalized, $needle);
	}

	/**
	 * For `checkboxes`: at least one of the listed option values is selected.
	 */
	private static function containsAny(mixed $normalized, mixed $ruleValue): bool {
		if (!is_array($normalized) || !is_array($ruleValue) || !array_is_list($ruleValue)) {
			return false;
		}
		foreach ($ruleValue as $item) {
			if (is_string($item) && in_array($item, $normalized, true)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * For `checkboxes`: every listed option value is selected. An empty list is never true.
	 */
	private static function containsAll(mixed $normalized, mixed $ruleValue): bool {
		if (!is_array($normalized) || !is_array($ruleValue) || !array_is_list($ruleValue) || $ruleValue === []) {
			return false;
		}
		foreach ($ruleValue as $item) {
			if (!is_string($item) || !in_array($item, $normalized, true)) {
				return false;
			}
		}
		return true;
	}

	private static function in(string $type, mixed $normalized, mixed $ruleValue): bool {
		if (!is_array($ruleValue) || !array_is_list($ruleValue)) {
			return false;
		}
		foreach ($ruleValue as $item) {
			$candidate = ValueNormalizer::normalize($type, $item);
			if ($candidate !== null && $candidate === $normalized) {
				return true;
			}
		}
		return false;
	}

	private static function ordered(string $type, string $op, mixed $normalized, mixed $ruleValue): bool {
		$other = ValueNormalizer::normalize($type, $ruleValue);

		if (is_float($normalized) && is_float($other)) {
			$cmp = $normalized <=> $other;
		} elseif (is_string($normalized) && is_string($other)) {
			// Dates and times: lexical order equals chronological order for the fixed formats.
			$cmp = strcmp($normalized, $other) <=> 0;
		} else {
			return false;
		}

		return match ($op) {
			'gt' => $cmp > 0,
			'gte' => $cmp >= 0,
			'lt' => $cmp < 0,
			'lte' => $cmp <= 0,
			default => false,
		};
	}
}
