<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions;

use OCA\Webforms\Forms\FieldValueValidator;
use OCA\Webforms\Rules\FieldType;

/**
 * The Tables columns that fit a form's fields, for "create a table from this form"
 * (docs/SCHEMA.md section 15.1). Pure, so it is unit tested.
 */
final class TablesSchema {
	public const MAX_COLUMNS = 100;
	public const MAX_TITLE = 200;

	/**
	 * One column for each answered field, in form order, then one for when the response arrived.
	 * Every column is optional on purpose: a field hidden by a rule or left blank has no answer, and
	 * Tables would reject the whole row if a required column stayed empty.
	 *
	 * @param list<array<string, mixed>> $fields in form order: `id`, `type`, `label`, and for choices `options`
	 * @return array{columns: list<array<string, mixed>>, sources: list<string>} `sources[i]` is what fills `columns[i]`
	 */
	public static function forFields(array $fields, string $submittedTitle = 'Submitted'): array {
		$columns = [];
		$sources = [];
		foreach ($fields as $field) {
			$id = $field['id'] ?? null;
			$type = $field['type'] ?? null;
			if (!is_string($id) || !is_string($type) || !FieldType::isInput($type) || count($columns) >= self::MAX_COLUMNS - 1) {
				continue;
			}
			$title = self::title($field['label'] ?? null, $id);
			$column = match ($type) {
				FieldType::TEXTAREA => ['type' => 'text', 'subtype' => 'long'],
				FieldType::NUMBER => ['type' => 'number', 'subtype' => '', 'decimals' => self::decimals($field['step'] ?? null)]
					+ (is_string($field['prefix'] ?? null) && $field['prefix'] !== '' ? ['prefix' => mb_substr($field['prefix'], 0, 20)] : [])
					+ (is_string($field['suffix'] ?? null) && $field['suffix'] !== '' ? ['suffix' => mb_substr($field['suffix'], 0, 20)] : []),
				FieldType::SELECT, FieldType::RADIO => ['type' => 'selection', 'subtype' => '', 'options' => self::options($field)],
				FieldType::CHECKBOXES => ['type' => 'selection', 'subtype' => 'multi', 'options' => self::options($field)],
				// Tables draws five stars, so a longer rating is a plain number.
				FieldType::RATING => FieldValueValidator::ratingMax($field) <= 5
					? ['type' => 'number', 'subtype' => 'stars']
					: ['type' => 'number', 'subtype' => '', 'decimals' => 0],
				FieldType::SCALE => ['type' => 'number', 'subtype' => '', 'decimals' => 0],
				FieldType::SLIDER => ['type' => 'number', 'subtype' => '', 'decimals' => self::decimals($field['step'] ?? null)],
				FieldType::CHECKBOX, FieldType::CONSENT => ['type' => 'selection', 'subtype' => 'check'],
				FieldType::DATETIME => ['type' => 'datetime', 'subtype' => ''],
				FieldType::DATE => ['type' => 'datetime', 'subtype' => 'date'],
				FieldType::TIME => ['type' => 'datetime', 'subtype' => 'time'],
				// Text, email, phone, url, and uploads (their file names).
				default => ['type' => 'text', 'subtype' => 'line'],
			};
			$columns[] = ['title' => $title, 'mandatory' => false] + $column;
			$sources[] = $id;
		}
		$columns[] = ['title' => $submittedTitle, 'mandatory' => false, 'type' => 'datetime', 'subtype' => ''];
		$sources[] = TablesValue::SUBMITTED;
		return ['columns' => $columns, 'sources' => $sources];
	}

	private static function title(mixed $label, string $fallback): string {
		$title = is_string($label) ? trim($label) : '';
		return mb_substr($title === '' ? $fallback : $title, 0, self::MAX_TITLE);
	}

	/**
	 * The option labels, without repeats (Tables rejects two options with the same label).
	 *
	 * @param array<string, mixed> $field
	 * @return list<string>
	 */
	private static function options(array $field): array {
		$labels = [];
		$seen = [];
		foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
			$label = is_array($option) ? trim((string)($option['label'] ?? $option['value'] ?? '')) : '';
			$key = mb_strtolower($label);
			if ($label === '' || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$labels[] = mb_substr($label, 0, 255);
		}
		return $labels;
	}

	/**
	 * How many decimals a number column shows: those of the field's step, else two.
	 */
	private static function decimals(mixed $step): int {
		if (!is_int($step) && !is_float($step)) {
			return 2;
		}
		$text = rtrim(rtrim(sprintf('%.6F', $step), '0'), '.');
		$dot = strpos($text, '.');
		return $dot === false ? 0 : strlen($text) - $dot - 1;
	}
}
