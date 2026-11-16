<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions;

use OCA\Webforms\Rules\FieldType;

/**
 * Turns an answer into what a Tables column stores, and says which answers a column can take
 * (docs/SCHEMA.md section 15). Pure: no Nextcloud classes, so it is unit tested.
 */
final class TablesValue {
	/** What a column can be filled from besides a field: when it was submitted, who, and the response id. */
	public const SUBMITTED = '@submitted';
	public const RESPONDENT = '@respondent';
	public const ENTRY = '@entry';
	public const META = [self::SUBMITTED, self::RESPONDENT, self::ENTRY];

	public const KIND_TEXT = 'text';
	public const KIND_NUMBER = 'number';
	public const KIND_STARS = 'stars';
	public const KIND_PROGRESS = 'progress';
	public const KIND_SINGLE = 'single';
	public const KIND_MULTI = 'multi';
	public const KIND_CHECK = 'check';
	public const KIND_DATETIME = 'datetime';
	public const KIND_DATE = 'date';
	public const KIND_TIME = 'time';

	/**
	 * What a Tables column holds, or null for a type this action cannot fill (people, relations).
	 *
	 * @param array<string, mixed> $column
	 */
	public static function kind(array $column): ?string {
		$type = (string)($column['type'] ?? '');
		// Tables spells some subtypes with the type in front ("selection-multi"), some without.
		$subtype = (string)($column['subtype'] ?? '');
		if (str_starts_with($subtype, $type . '-')) {
			$subtype = substr($subtype, strlen($type) + 1);
		}
		return match ($type) {
			'text' => self::KIND_TEXT,
			'number' => match ($subtype) {
				'stars' => self::KIND_STARS,
				'progress' => self::KIND_PROGRESS,
				default => self::KIND_NUMBER,
			},
			'selection' => match ($subtype) {
				'multi' => self::KIND_MULTI,
				'check' => self::KIND_CHECK,
				default => self::KIND_SINGLE,
			},
			'datetime' => match ($subtype) {
				'date' => self::KIND_DATE,
				'time' => self::KIND_TIME,
				default => self::KIND_DATETIME,
			},
			default => null,
		};
	}

	/**
	 * Whether an answer of this source (a field type, or one of META) can go into this column.
	 *
	 * @param array<string, mixed> $column
	 */
	public static function compatible(string $source, array $column): bool {
		$kind = self::kind($column);
		if ($kind === null) {
			return false;
		}
		if (in_array($source, self::META, true)) {
			return match ($kind) {
				self::KIND_TEXT => true,
				self::KIND_NUMBER, self::KIND_STARS, self::KIND_PROGRESS => $source === self::ENTRY,
				self::KIND_DATETIME, self::KIND_DATE, self::KIND_TIME => $source === self::SUBMITTED,
				default => false,
			};
		}
		return match ($kind) {
			// Anything has a text form, including choices and uploaded file names.
			self::KIND_TEXT => FieldType::isInput($source),
			self::KIND_NUMBER, self::KIND_STARS, self::KIND_PROGRESS => FieldType::isNumeric($source),
			self::KIND_SINGLE => $source === FieldType::SELECT || $source === FieldType::RADIO,
			self::KIND_MULTI => $source === FieldType::CHECKBOXES,
			self::KIND_CHECK => FieldType::isBoolean($source),
			self::KIND_DATE => $source === FieldType::DATE,
			self::KIND_DATETIME => $source === FieldType::DATE || $source === FieldType::DATETIME,
			self::KIND_TIME => $source === FieldType::TIME,
			default => false,
		};
	}

	/**
	 * The value for a column, or null when there is nothing to write (unanswered, or a choice that has
	 * no matching option in the column). A column is only filled when it can take this source, see
	 * compatible().
	 *
	 * @param array<string, mixed> $field the form field, or [] for a META source
	 * @param array<string, mixed> $column
	 * @param array{submittedAt: int, userId: ?string, entryId: int} $meta
	 */
	public static function convert(string $source, array $field, mixed $value, array $column, array $meta): mixed {
		$kind = self::kind($column);
		if ($kind === null || !self::compatible($source, $column)) {
			return null;
		}

		if ($source === self::SUBMITTED) {
			return match ($kind) {
				self::KIND_DATE => gmdate('Y-m-d', $meta['submittedAt']),
				self::KIND_TIME => gmdate('H:i', $meta['submittedAt']),
				self::KIND_DATETIME => gmdate('Y-m-d H:i', $meta['submittedAt']),
				default => gmdate('Y-m-d\TH:i:s\Z', $meta['submittedAt']),
			};
		}
		if ($source === self::RESPONDENT) {
			return $meta['userId'];
		}
		if ($source === self::ENTRY) {
			return $kind === self::KIND_TEXT ? (string)$meta['entryId'] : $meta['entryId'];
		}

		if ($value === null || $value === '' || $value === []) {
			return null;
		}

		switch ($kind) {
			case self::KIND_TEXT:
				return self::asText($field, $value);
			case self::KIND_NUMBER:
				return is_numeric($value) ? $value + 0 : null;
			case self::KIND_STARS:
			case self::KIND_PROGRESS:
				return is_numeric($value) ? (int)round((float)$value) : null;
			case self::KIND_SINGLE:
				return is_string($value) ? self::optionId($column, self::labelOf($field, $value)) : null;
			case self::KIND_MULTI:
				if (!is_array($value)) {
					return null;
				}
				$ids = [];
				foreach ($value as $one) {
					$id = is_string($one) ? self::optionId($column, self::labelOf($field, $one)) : null;
					if ($id !== null) {
						$ids[] = $id;
					}
				}
				return $ids === [] ? null : $ids;
			case self::KIND_CHECK:
				return $value === true;
			case self::KIND_DATE:
				return is_string($value) ? $value : null;
			case self::KIND_DATETIME:
				if (!is_string($value)) {
					return null;
				}
				return $source === FieldType::DATETIME ? str_replace('T', ' ', $value) : $value . ' 00:00';
			case self::KIND_TIME:
				return is_string($value) ? $value : null;
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private static function asText(array $field, mixed $value): string {
		if (is_bool($value)) {
			return $value ? 'Yes' : 'No';
		}
		if (is_array($value)) {
			$parts = [];
			foreach ($value as $one) {
				if (is_array($one)) {
					// An uploaded file: its name only, where it is stored is private.
					$parts[] = (string)($one['name'] ?? '');
				} else {
					$parts[] = self::labelOf($field, (string)$one);
				}
			}
			return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
		}
		if (($field['type'] ?? null) === FieldType::DATETIME) {
			return str_replace('T', ' ', (string)$value);
		}
		return self::labelOf($field, (string)$value);
	}

	/**
	 * A choice is stored by its option value, and read by its label.
	 *
	 * @param array<string, mixed> $field
	 */
	private static function labelOf(array $field, string $value): string {
		foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
			if (is_array($option) && (string)($option['value'] ?? '') === $value) {
				return (string)($option['label'] ?? $value);
			}
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $column
	 */
	private static function optionId(array $column, string $label): ?int {
		$wanted = mb_strtolower(trim($label));
		foreach (is_array($column['options'] ?? null) ? $column['options'] : [] as $option) {
			if (is_array($option) && mb_strtolower(trim((string)($option['label'] ?? ''))) === $wanted) {
				return (int)$option['id'];
			}
		}
		return null;
	}
}
