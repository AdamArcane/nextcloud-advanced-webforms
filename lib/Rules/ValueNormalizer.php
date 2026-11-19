<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Rules;

/**
 * Turns a raw submitted value, or a rule's value, into the form used for
 * comparison. null means "empty". The TypeScript twin is src/rules/normalize.ts
 * and both must behave identically (docs/SCHEMA.md section 6.2).
 */
final class ValueNormalizer {
	/**
	 * Characters removed by trim(): the set JavaScript's String.prototype.trim()
	 * removes (white space, line terminators, BOM).
	 */
	private const TRIM_PATTERN = '/^[\t\n\x0B\f\r\p{Z}\x{FEFF}]+|[\t\n\x0B\f\r\p{Z}\x{FEFF}]+$/uD';

	/**
	 * @return string|float|bool|list<string>|null
	 */
	public static function normalize(string $type, mixed $raw): string|float|bool|array|null {
		switch ($type) {
			case FieldType::TEXT:
			case FieldType::TEXTAREA:
			case FieldType::EMAIL:
			case FieldType::URL:
			case FieldType::HIDDEN:
				return self::text($raw);
			case FieldType::PHONE:
				return self::phone($raw);
			case FieldType::NUMBER:
			case FieldType::RATING:
			case FieldType::SCALE:
			case FieldType::SLIDER:
				return self::number($raw);
			case FieldType::COLOR:
				return (is_string($raw) && preg_match('/^#[0-9a-fA-F]{6}$/D', $raw) === 1) ? strtolower($raw) : null;
			case FieldType::SIGNATURE:
				// Only whether one was drawn matters to a rule.
				return (is_string($raw) && str_starts_with($raw, 'data:image/png;base64,')) ? true : null;
			case FieldType::MATRIX:
			case FieldType::DATE_RANGE:
			case FieldType::NAME:
			case FieldType::ADDRESS:
				// Only whether anything was answered matters to a rule.
				return (is_array($raw) && $raw !== [] && !array_is_list($raw)) ? true : null;
			case FieldType::SELECT:
			case FieldType::RADIO:
				return (is_string($raw) && $raw !== '' && mb_check_encoding($raw, 'UTF-8')) ? $raw : null;
			case FieldType::CHECKBOXES:
			case FieldType::FILE:
				return self::list($raw);
			case FieldType::CHECKBOX:
			case FieldType::CONSENT:
				return is_bool($raw) ? $raw : null;
			case FieldType::DATETIME:
				return (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $raw) === 1) ? $raw : null;
			case FieldType::DATE:
				return (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw) === 1) ? $raw : null;
			case FieldType::TIME:
				return (is_string($raw) && preg_match('/^\d{2}:\d{2}$/D', $raw) === 1) ? $raw : null;
			default:
				return null;
		}
	}

	/**
	 * Trim Unicode white space, matching JavaScript's trim().
	 */
	public static function trim(string $value): string {
		return preg_replace(self::TRIM_PATTERN, '', $value) ?? '';
	}

	private static function text(mixed $raw): ?string {
		if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
			return null;
		}
		$trimmed = self::trim($raw);
		if ($trimmed === '') {
			return null;
		}
		return mb_strtolower($trimmed, 'UTF-8');
	}

	private static function phone(mixed $raw): ?string {
		if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
			return null;
		}
		$trimmed = self::trim($raw);
		$digits = preg_replace('/\D+/', '', $trimmed) ?? '';
		if ($digits === '') {
			return null;
		}
		return (str_starts_with($trimmed, '+') ? '+' : '') . $digits;
	}

	private static function number(mixed $raw): ?float {
		if (is_int($raw)) {
			return (float)$raw;
		}
		if (is_float($raw)) {
			return is_finite($raw) ? $raw : null;
		}
		if (is_string($raw) && mb_check_encoding($raw, 'UTF-8')) {
			$trimmed = self::trim($raw);
			if (preg_match('/^-?\d+(\.\d+)?$/D', $trimmed) === 1) {
				return (float)$trimmed;
			}
		}
		return null;
	}

	/**
	 * @return list<string>|null
	 */
	private static function list(mixed $raw): ?array {
		if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
			return null;
		}
		foreach ($raw as $item) {
			if (!is_string($item)) {
				return null;
			}
		}
		return $raw;
	}
}
