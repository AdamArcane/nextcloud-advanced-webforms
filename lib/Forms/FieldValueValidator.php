<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Forms;

use OCA\AdvancedForms\Rules\FieldType;
use OCA\AdvancedForms\Rules\ValueNormalizer;

/**
 * Validates one submitted value against its field definition and returns the
 * canonical value to store (docs/SCHEMA.md section 4.2, section 10 step 6).
 *
 * This is stricter than rule normalization: rules compare loosely and lowercase
 * text, while stored values keep the respondent's text (trimmed).
 */
final class FieldValueValidator {
	public const MAX_TEXT = 2000;
	public const MAX_TEXTAREA = 20000;
	public const MAX_EMAIL = 254;

	/**
	 * @param array<string, mixed> $field the field definition
	 * @return array{0: string|int|float|bool|list<string>|null, 1: string|null}
	 *   [value to store, error code]. A null value with no error means "empty".
	 */
	public static function clean(array $field, mixed $raw): array {
		$type = is_string($field['type'] ?? null) ? $field['type'] : '';

		return match ($type) {
			FieldType::TEXT => self::text($field, $raw, self::MAX_TEXT),
			FieldType::TEXTAREA => self::text($field, $raw, self::MAX_TEXTAREA),
			FieldType::EMAIL => self::email($raw),
			FieldType::PHONE => self::phone($raw),
			FieldType::NUMBER => self::number($field, $raw),
			FieldType::SELECT, FieldType::RADIO => self::choice($field, $raw),
			FieldType::CHECKBOXES => self::multi($field, $raw),
			FieldType::CHECKBOX => is_bool($raw) || $raw === null ? [$raw, null] : [null, 'invalid_type'],
			FieldType::DATE => self::date($raw),
			FieldType::TIME => self::time($raw),
			default => [null, 'invalid_type'],
		};
	}

	/**
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function trimmed(mixed $raw): array {
		if ($raw === null) {
			return [null, null];
		}
		if (!is_string($raw)) {
			return [null, 'invalid_type'];
		}
		if (preg_match('//u', $raw) !== 1) {
			return [null, 'invalid_encoding'];
		}
		$value = ValueNormalizer::trim($raw);
		return [$value === '' ? null : $value, null];
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function text(array $field, mixed $raw, int $cap): array {
		[$value, $error] = self::trimmed($raw);
		if ($value === null) {
			return [null, $error];
		}
		$max = $cap;
		if (is_int($field['maxLength'] ?? null) && $field['maxLength'] >= 1) {
			$max = min($cap, $field['maxLength']);
		}
		return mb_strlen($value) > $max ? [null, 'too_long'] : [$value, null];
	}

	/**
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function email(mixed $raw): array {
		[$value, $error] = self::trimmed($raw);
		if ($value === null) {
			return [null, $error];
		}
		if (strlen($value) > self::MAX_EMAIL || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
			return [null, 'invalid_email'];
		}
		return [$value, null];
	}

	/**
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function phone(mixed $raw): array {
		[$value, $error] = self::trimmed($raw);
		if ($value === null) {
			return [null, $error];
		}
		if (preg_match('/^\+?[0-9 ().-]+$/D', $value) !== 1) {
			return [null, 'invalid_phone'];
		}
		$digits = strlen(preg_replace('/\D/', '', $value) ?? '');
		return $digits < 7 || $digits > 15 ? [null, 'invalid_phone'] : [$value, null];
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array{0: int|float|null, 1: string|null}
	 */
	private static function number(array $field, mixed $raw): array {
		if ($raw === null || (is_string($raw) && ValueNormalizer::trim($raw) === '')) {
			return [null, null];
		}
		$number = ValueNormalizer::normalize(FieldType::NUMBER, $raw);
		if (!is_float($number)) {
			return [null, 'invalid_number'];
		}
		$min = self::numeric($field['min'] ?? null);
		$max = self::numeric($field['max'] ?? null);
		if ($min !== null && $number < $min) {
			return [null, 'below_min'];
		}
		if ($max !== null && $number > $max) {
			return [null, 'above_max'];
		}
		$step = self::numeric($field['step'] ?? null);
		if ($step !== null && $step > 0) {
			$ratio = ($number - ($min ?? 0.0)) / $step;
			if (abs($ratio - round($ratio)) > 1e-9 * max(1.0, abs($ratio))) {
				return [null, 'not_step'];
			}
		}
		$whole = floor($number) === $number && abs($number) < 1e15;
		return [$whole ? (int)$number : $number, null];
	}

	private static function numeric(mixed $value): ?float {
		return is_int($value) || is_float($value) ? (float)$value : null;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return list<string>
	 */
	private static function optionValues(array $field): array {
		$values = [];
		foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
			if (is_array($option) && is_string($option['value'] ?? null)) {
				$values[] = $option['value'];
			}
		}
		return $values;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function choice(array $field, mixed $raw): array {
		if ($raw === null || $raw === '') {
			return [null, null];
		}
		if (!is_string($raw)) {
			return [null, 'invalid_type'];
		}
		return in_array($raw, self::optionValues($field), true) ? [$raw, null] : [null, 'invalid_option'];
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array{0: list<string>|null, 1: string|null}
	 */
	private static function multi(array $field, mixed $raw): array {
		if ($raw === null || $raw === []) {
			return [null, null];
		}
		if (!is_array($raw) || !array_is_list($raw)) {
			return [null, 'invalid_type'];
		}
		$allowed = self::optionValues($field);
		foreach ($raw as $item) {
			if (!is_string($item)) {
				return [null, 'invalid_type'];
			}
			if (!in_array($item, $allowed, true)) {
				return [null, 'invalid_option'];
			}
		}
		if (count($raw) !== count(array_unique($raw))) {
			return [null, 'duplicate_option'];
		}
		$min = is_int($field['minSelected'] ?? null) ? $field['minSelected'] : null;
		$max = is_int($field['maxSelected'] ?? null) ? $field['maxSelected'] : null;
		if ($min !== null && count($raw) < $min) {
			return [null, 'too_few'];
		}
		if ($max !== null && count($raw) > $max) {
			return [null, 'too_many'];
		}
		// Store in the form's option order so equal answers are stored equally.
		return [array_values(array_intersect($allowed, $raw)), null];
	}

	/**
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function date(mixed $raw): array {
		if ($raw === null || $raw === '') {
			return [null, null];
		}
		if (!is_string($raw) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $raw, $m) !== 1) {
			return [null, 'invalid_date'];
		}
		return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? [$raw, null] : [null, 'invalid_date'];
	}

	/**
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function time(mixed $raw): array {
		if ($raw === null || $raw === '') {
			return [null, null];
		}
		if (!is_string($raw) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $raw) !== 1) {
			return [null, 'invalid_time'];
		}
		return [$raw, null];
	}
}
