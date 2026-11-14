<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Actions;

/**
 * Fills `{field_id}` placeholders in action text with a respondent's answers
 * (docs/SCHEMA.md section 7.2). Values are always inserted as plain text.
 */
final class Template {
	/** Free text: message and email body. */
	public const TEXT = 'text';
	/** A single header line: newlines are removed so a value cannot inject headers. */
	public const SUBJECT = 'subject';
	/** A URL: values are percent-encoded. */
	public const URL = 'url';

	/**
	 * @param array<string, array<string, mixed>> $fields the definition's fields
	 * @param array<string, mixed> $data the entry's answers keyed by field ID
	 */
	public static function render(string $template, array $fields, array $data, string $mode = self::TEXT): string {
		$result = preg_replace_callback('/\{([^{}]*)\}/', static function (array $match) use ($fields, $data, $mode): string {
			$id = $match[1];
			if (!isset($fields[$id])) {
				// Not a known field: leave the braces alone rather than guess.
				return $match[0];
			}
			$value = self::display($fields[$id], $data[$id] ?? null);
			return match ($mode) {
				self::SUBJECT => trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)),
				self::URL => rawurlencode($value),
				default => $value,
			};
		}, $template);
		return $result ?? $template;
	}

	/**
	 * The text a person would expect to see for an answer: option labels for
	 * choices, Yes or No for a checkbox, and nothing for a missing answer.
	 *
	 * @param array<string, mixed> $field
	 */
	public static function display(array $field, mixed $value): string {
		if ($value === null) {
			return '';
		}
		if (is_bool($value)) {
			return $value ? 'Yes' : 'No';
		}
		if (($field['type'] ?? null) === 'file' && is_array($value)) {
			// Stored files: only their names are ever put in text.
			return implode(', ', array_map(static fn (mixed $file): string => is_array($file) && is_string($file['name'] ?? null) ? $file['name'] : '', array_values($value)));
		}
		if (is_array($value)) {
			return implode(', ', array_map(static fn (mixed $item): string => self::optionLabel($field, $item), array_values($value)));
		}
		if (is_int($value) || is_float($value)) {
			return self::number($value);
		}
		return self::optionLabel($field, $value);
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private static function optionLabel(array $field, mixed $value): string {
		$value = is_scalar($value) ? (string)$value : '';
		$options = $field['options'] ?? null;
		if (is_array($options)) {
			foreach ($options as $option) {
				if (is_array($option) && ($option['value'] ?? null) === $value && is_string($option['label'] ?? null)) {
					return $option['label'];
				}
			}
		}
		return $value;
	}

	private static function number(int|float $value): string {
		if (is_int($value) || floor($value) === $value) {
			return (string)(int)$value;
		}
		return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
	}
}
