<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

use OCA\Webforms\Rules\FieldType;
use OCA\Webforms\Rules\ValueNormalizer;

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
	public const MAX_URL = 2048;
	public const MAX_PART = 200;
	/** The size of a signature as text, about 150 KB of picture. */
	public const MAX_SIGNATURE = 200000;
	public const NAME_PARTS = ['prefix', 'first', 'middle', 'last', 'suffix'];
	public const NAME_DEFAULT = ['first', 'last'];
	/** Parts that must be filled when a name is required. */
	public const NAME_REQUIRED = ['first', 'last'];
	public const ADDRESS_PARTS = ['street', 'street2', 'city', 'region', 'postal', 'country'];
	public const ADDRESS_REQUIRED = ['street', 'city', 'postal', 'country'];
	public const SLIDER_MIN = 0;
	public const SLIDER_MAX = 100;
	/** What ISecureRandom hands out for an upload: lower case letters and digits. */
	public const UPLOAD_ID = '/^[a-z0-9]{32}$/D';

	/**
	 * @param array<string, mixed> $field the field definition
	 * @return array{0: string|int|float|bool|list<string>|null, 1: string|null}
	 *   [value to store, error code]. A null value with no error means "empty".
	 */
	public static function clean(array $field, mixed $raw): array {
		$type = FieldType::valueType($field);

		return match ($type) {
			FieldType::TEXT, FieldType::HIDDEN => self::text($field, $raw, self::MAX_TEXT),
			FieldType::TEXTAREA => self::text($field, $raw, self::MAX_TEXTAREA),
			FieldType::EMAIL => self::email($raw),
			FieldType::PHONE => self::phone($raw),
			FieldType::URL => self::url($raw),
			FieldType::NUMBER => self::number($field, $raw),
			FieldType::SELECT, FieldType::RADIO => self::choice($field, $raw),
			FieldType::CHECKBOXES => self::multi($field, $raw),
			FieldType::RATING => self::whole($raw, 1, self::ratingMax($field)),
			FieldType::SCALE => self::whole($raw, self::scaleMin($field), self::scaleMax($field)),
			FieldType::DATETIME => self::dateTime($field, $raw),
			FieldType::CHECKBOX, FieldType::CONSENT => is_bool($raw) || $raw === null ? [$raw, null] : [null, 'invalid_type'],
			FieldType::DATE => self::date($field, $raw),
			FieldType::TIME => self::time($field, $raw),
			FieldType::FILE => self::files($field, $raw),
			FieldType::SLIDER => self::number(self::sliderLimits($field), $raw),
			FieldType::COLOR => self::color($raw),
			FieldType::SIGNATURE => self::signature($raw),
			FieldType::MATRIX => self::matrix($field, $raw),
			FieldType::DATE_RANGE => self::dateRange($field, $raw),
			FieldType::NAME, FieldType::ADDRESS => self::parts($field, $raw),
			default => [null, 'invalid_type'],
		};
	}

	/**
	 * A slider's own limits with the defaults filled in, so a slider with no settings is 0 to 100 by 1.
	 *
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>
	 */
	public static function sliderLimits(array $field): array {
		return [
			'min' => self::numeric($field['min'] ?? null) ?? self::SLIDER_MIN,
			'max' => self::numeric($field['max'] ?? null) ?? self::SLIDER_MAX,
			'step' => self::numeric($field['step'] ?? null) ?? 1,
		];
	}

	/**
	 * Whether every part that has to be answered was. Only asked of a required field: a name needs its
	 * first and last part, an address its street, city, postcode and country, a matrix every row.
	 *
	 * @param array<string, mixed> $field
	 */
	public static function isComplete(array $field, mixed $value): bool {
		$type = is_string($field['type'] ?? null) ? $field['type'] : '';
		if (!is_array($value)) {
			return true;
		}
		if ($type === FieldType::MATRIX) {
			foreach (self::matrixRows($field) as $row) {
				if (!isset($value[$row])) {
					return false;
				}
			}
			return true;
		}
		if ($type === FieldType::NAME || $type === FieldType::ADDRESS) {
			$needed = $type === FieldType::NAME ? self::NAME_REQUIRED : self::ADDRESS_REQUIRED;
			foreach (array_intersect(self::partsOf($field), $needed) as $part) {
				if (!isset($value[$part])) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * The parts a name or an address field asks for, in their fixed order.
	 *
	 * @param array<string, mixed> $field
	 * @return list<string>
	 */
	public static function partsOf(array $field): array {
		$name = ($field['type'] ?? null) === FieldType::NAME;
		$known = $name ? self::NAME_PARTS : self::ADDRESS_PARTS;
		$chosen = is_array($field['parts'] ?? null) ? $field['parts'] : ($name ? self::NAME_DEFAULT : self::ADDRESS_PARTS);
		return array_values(array_intersect($known, $chosen));
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array{0: array<string, string>|null, 1: string|null}
	 */
	private static function parts(array $field, mixed $raw): array {
		if ($raw === null || $raw === []) {
			return [null, null];
		}
		if (!is_array($raw) || array_is_list($raw)) {
			return [null, 'invalid_type'];
		}
		$allowed = self::partsOf($field);
		foreach (array_keys($raw) as $key) {
			if (!in_array($key, $allowed, true)) {
				return [null, 'invalid_type'];
			}
		}
		$clean = [];
		foreach ($allowed as $part) {
			[$value, $error] = self::trimmed($raw[$part] ?? null);
			if ($error !== null) {
				return [null, $error];
			}
			if ($value === null) {
				continue;
			}
			if (mb_strlen($value) > self::MAX_PART) {
				return [null, 'too_long'];
			}
			$clean[$part] = $value;
		}
		return [$clean === [] ? null : $clean, null];
	}

	/**
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function color(mixed $raw): array {
		if ($raw === null || $raw === '') {
			return [null, null];
		}
		if (!is_string($raw)) {
			return [null, 'invalid_type'];
		}
		return preg_match('/^#[0-9a-fA-F]{6}$/D', $raw) === 1 ? [strtolower($raw), null] : [null, 'invalid_color'];
	}

	/**
	 * A signature is a PNG as a `data:` address. The bytes are decoded and the PNG header checked, so
	 * nothing else can be stored, and the size is capped.
	 *
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function signature(mixed $raw): array {
		if ($raw === null || $raw === '') {
			return [null, null];
		}
		$prefix = 'data:image/png;base64,';
		if (!is_string($raw) || !str_starts_with($raw, $prefix)) {
			return [null, 'invalid_signature'];
		}
		if (strlen($raw) > self::MAX_SIGNATURE) {
			return [null, 'signature_too_large'];
		}
		$bytes = base64_decode(substr($raw, strlen($prefix)), true);
		if ($bytes === false || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
			return [null, 'invalid_signature'];
		}
		return [$raw, null];
	}

	/**
	 * @param array<string, mixed> $field
	 * @return list<string> the row values
	 */
	public static function matrixRows(array $field): array {
		$rows = [];
		foreach (is_array($field['questions'] ?? null) ? $field['questions'] : [] as $row) {
			if (is_array($row) && is_string($row['value'] ?? null)) {
				$rows[] = $row['value'];
			}
		}
		return $rows;
	}

	/**
	 * One column per row, or several when the field allows `multiple`. Stored by row value, in row order.
	 *
	 * @param array<string, mixed> $field
	 * @return array{0: array<string, string|list<string>>|null, 1: string|null}
	 */
	private static function matrix(array $field, mixed $raw): array {
		if ($raw === null || $raw === []) {
			return [null, null];
		}
		if (!is_array($raw) || array_is_list($raw)) {
			return [null, 'invalid_type'];
		}
		$rows = self::matrixRows($field);
		$columns = self::optionValues($field);
		$multiple = ($field['multiple'] ?? false) === true;
		foreach (array_keys($raw) as $key) {
			if (!in_array((string)$key, $rows, true)) {
				return [null, 'invalid_option'];
			}
		}
		$clean = [];
		foreach ($rows as $row) {
			$answer = $raw[$row] ?? null;
			if ($answer === null || $answer === '' || $answer === []) {
				continue;
			}
			if ($multiple) {
				if (!is_array($answer) || !array_is_list($answer)) {
					return [null, 'invalid_type'];
				}
				foreach ($answer as $one) {
					if (!is_string($one)) {
						return [null, 'invalid_type'];
					}
					if (!in_array($one, $columns, true)) {
						return [null, 'invalid_option'];
					}
				}
				if (count($answer) !== count(array_unique($answer))) {
					return [null, 'duplicate_option'];
				}
				$clean[$row] = array_values(array_intersect($columns, $answer));
			} else {
				if (!is_string($answer)) {
					return [null, 'invalid_type'];
				}
				if (!in_array($answer, $columns, true)) {
					return [null, 'invalid_option'];
				}
				$clean[$row] = $answer;
			}
		}
		return [$clean === [] ? null : $clean, null];
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array{0: array{start: string, end: string}|null, 1: string|null}
	 */
	private static function dateRange(array $field, mixed $raw): array {
		if ($raw === null || $raw === []) {
			return [null, null];
		}
		if (!is_array($raw) || array_is_list($raw) || array_diff(array_keys($raw), ['start', 'end']) !== []) {
			return [null, 'invalid_type'];
		}
		$start = $raw['start'] ?? null;
		$end = $raw['end'] ?? null;
		$startEmpty = $start === null || $start === '';
		$endEmpty = $end === null || $end === '';
		if ($startEmpty && $endEmpty) {
			return [null, null];
		}
		if ($startEmpty || $endEmpty) {
			return [null, 'incomplete_range'];
		}
		if (!self::isDate($start) || !self::isDate($end)) {
			return [null, 'invalid_date'];
		}
		if ($end < $start) {
			return [null, 'range_reversed'];
		}
		// Whole days from the first date to the last: 0 for one day, 1 for the next day.
		$days = (int)(new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days;
		if (is_int($field['minDays'] ?? null) && $days < $field['minDays']) {
			return [null, 'range_too_short'];
		}
		if (is_int($field['maxDays'] ?? null) && $days > $field['maxDays']) {
			return [null, 'range_too_long'];
		}
		if (self::isDate($field['min'] ?? null) && $start < $field['min']) {
			return [null, 'date_too_early'];
		}
		if (self::isDate($field['max'] ?? null) && $end > $field['max']) {
			return [null, 'date_too_late'];
		}
		return [['start' => $start, 'end' => $end], null];
	}

	/**
	 * An upload field's value is the list of upload ids the respondent's browser got back from the
	 * upload endpoint. The service swaps them for stored files after this check.
	 *
	 * @param array<string, mixed> $field
	 * @return array{0: list<string>|null, 1: string|null}
	 */
	private static function files(array $field, mixed $raw): array {
		if ($raw === null || $raw === []) {
			return [null, null];
		}
		if (!is_array($raw) || !array_is_list($raw)) {
			return [null, 'invalid_type'];
		}
		foreach ($raw as $item) {
			if (!is_string($item) || preg_match(self::UPLOAD_ID, $item) !== 1) {
				return [null, 'invalid_type'];
			}
		}
		if (count($raw) !== count(array_unique($raw))) {
			return [null, 'duplicate_file'];
		}
		return count($raw) > UploadPolicy::maxFiles($field) ? [null, 'too_many_files'] : [$raw, null];
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
		if (mb_strlen($value) > $max) {
			return [null, 'too_long'];
		}
		if (is_int($field['minLength'] ?? null) && $field['minLength'] >= 1 && mb_strlen($value) < $field['minLength']) {
			return [null, 'too_short'];
		}
		return [$value, null];
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
	 * A web address. Only http and https are accepted, so a stored value can never be a `javascript:`
	 * or `data:` link when an owner opens it from the responses. Without a scheme, `https://` is added,
	 * since people type `example.com`.
	 *
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function url(mixed $raw): array {
		[$value, $error] = self::trimmed($raw);
		if ($value === null) {
			return [null, $error];
		}
		if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $value) !== 1) {
			$value = 'https://' . $value;
		}
		if (strlen($value) > self::MAX_URL || preg_match('/[\s<>"]/u', $value) === 1) {
			return [null, 'invalid_url'];
		}
		$parts = parse_url($value);
		$scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
		$host = is_array($parts) ? (string)($parts['host'] ?? '') : '';
		if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !str_contains($host, '.') || str_starts_with($host, '.') || str_ends_with($host, '.')) {
			return [null, 'invalid_url'];
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

	/** The most stars a rating field offers: its `max`, 5 without one. */
	public static function ratingMax(array $field): int {
		return is_int($field['max'] ?? null) ? $field['max'] : 5;
	}

	/** Where a scale starts: 0 or 1, 1 without a `min`. */
	public static function scaleMin(array $field): int {
		return ($field['min'] ?? null) === 0 ? 0 : 1;
	}

	/** Where a scale ends: its `max`, 5 without one. */
	public static function scaleMax(array $field): int {
		return is_int($field['max'] ?? null) ? $field['max'] : 5;
	}

	/**
	 * A whole number from $min to $max, the answer of a rating or a scale.
	 *
	 * @return array{0: int|null, 1: string|null}
	 */
	private static function whole(mixed $raw, int $min, int $max): array {
		[$value, $error] = self::number(['min' => $min, 'max' => $max, 'step' => 1], $raw);
		return [$value === null ? null : (int)$value, $error];
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
	 * @param array<string, mixed> $field
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function date(array $field, mixed $raw): array {
		if ($raw === null || $raw === '') {
			return [null, null];
		}
		if (!self::isDate($raw)) {
			return [null, 'invalid_date'];
		}
		// ISO dates sort the same as text, so a string comparison is a date comparison.
		if (self::isDate($field['min'] ?? null) && $raw < $field['min']) {
			return [null, 'date_too_early'];
		}
		if (self::isDate($field['max'] ?? null) && $raw > $field['max']) {
			return [null, 'date_too_late'];
		}
		return [$raw, null];
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function time(array $field, mixed $raw): array {
		if ($raw === null || $raw === '') {
			return [null, null];
		}
		if (!self::isTime($raw)) {
			return [null, 'invalid_time'];
		}
		if (self::isTime($field['min'] ?? null) && $raw < $field['min']) {
			return [null, 'time_too_early'];
		}
		if (self::isTime($field['max'] ?? null) && $raw > $field['max']) {
			return [null, 'time_too_late'];
		}
		return [$raw, null];
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function dateTime(array $field, mixed $raw): array {
		if ($raw === null || $raw === '') {
			return [null, null];
		}
		if (!self::isDateTime($raw)) {
			return [null, 'invalid_date'];
		}
		// Fixed width, so text order is time order.
		if (self::isDateTime($field['min'] ?? null) && $raw < $field['min']) {
			return [null, 'date_too_early'];
		}
		if (self::isDateTime($field['max'] ?? null) && $raw > $field['max']) {
			return [null, 'date_too_late'];
		}
		return [$raw, null];
	}

	/** A real date and a time of day as `YYYY-MM-DDTHH:MM`, with no time zone: the respondent's own clock. */
	public static function isDateTime(mixed $value): bool {
		return is_string($value) && strlen($value) === 16 && $value[10] === 'T'
			&& self::isDate(substr($value, 0, 10)) && self::isTime(substr($value, 11));
	}

	/** A real calendar date as `YYYY-MM-DD`. */
	public static function isDate(mixed $value): bool {
		return is_string($value)
			&& preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) === 1
			&& checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
	}

	/** A time of day as `HH:MM`, 24 hour. */
	public static function isTime(mixed $value): bool {
		return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $value) === 1;
	}
}
