<?php

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Webforms\Forms;

/**
 * Puts a translation into a bundled template (docs/SCHEMA.md section 13.4). The browser sends a map from the
 * English text to its translation. Only properties that are words a respondent reads are replaced, never an id,
 * a value, a type or a rule, so a map cannot change what a form does.
 */
final class TemplateText {
	/** @var list<string> */
	private const KEYS = ['title', 'description', 'label', 'text', 'placeholder', 'submitLabel', 'closedMessage', 'minLabel', 'maxLabel', 'reviewTitle'];

	public const MAX_ENTRIES = 300;
	public const MAX_LENGTH = 1000;

	/**
	 * @param array<string, mixed> $definition
	 * @param array<mixed> $strings English text to its translation
	 * @return array<string, mixed>
	 */
	public static function translate(array $definition, array $strings): array {
		$map = [];
		foreach ($strings as $from => $to) {
			if (count($map) >= self::MAX_ENTRIES) {
				break;
			}
			if (is_string($from) && is_string($to) && $to !== '' && mb_strlen($to) <= self::MAX_LENGTH) {
				$map[$from] = $to;
			}
		}
		if ($map === []) {
			return $definition;
		}
		/** @var array<string, mixed> */
		return self::walk($definition, $map, null);
	}

	/**
	 * @param array<mixed> $node
	 * @param array<string, string> $map
	 * @return array<mixed>
	 */
	private static function walk(array $node, array $map, ?string $key): array {
		foreach ($node as $k => $value) {
			if (is_array($value)) {
				$node[$k] = self::walk($value, $map, is_string($k) ? $k : $key);
			} elseif (is_string($value) && is_string($k) && in_array($k, self::KEYS, true) && isset($map[$value])) {
				$node[$k] = $map[$value];
			}
		}
		return $node;
	}
}
