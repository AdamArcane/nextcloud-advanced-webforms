<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions;

use OCA\Webforms\Rules\FieldType;

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
	 * In an email body, `{all_fields}` stands for every answer the respondent gave, one per line
	 * with its label. A field that really has this id keeps its own meaning.
	 */
	public const ALL_FIELDS = 'all_fields';

	/**
	 * @param array<string, array<string, mixed>> $fields the definition's fields
	 * @param array<string, mixed> $data the entry's answers keyed by field ID
	 * @param string|null $allFields what `{all_fields}` becomes, or null to leave it alone
	 */
	public static function render(string $template, array $fields, array $data, string $mode = self::TEXT, ?string $allFields = null): string {
		$result = preg_replace_callback('/\{([^{}]*)\}/', static function (array $match) use ($fields, $data, $mode, $allFields): string {
			$id = $match[1];
			if (!isset($fields[$id])) {
				if ($id === self::ALL_FIELDS && $allFields !== null) {
					return $allFields;
				}
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

	/** Whether the text asks for every answer, and no field has taken that name for itself. */
	public static function usesAllFields(string $template, array $fields): bool {
		return !isset($fields[self::ALL_FIELDS]) && str_contains($template, '{' . self::ALL_FIELDS . '}');
	}

	/**
	 * The questions the respondent answered, in the order the form shows them, each with its label and
	 * the answer as text. Questions left empty are skipped, and so are hidden ones, whose answers the
	 * server never stored.
	 *
	 * @param array<string, array<string, mixed>> $fields
	 * @param array<string, mixed> $data
	 * @param array<mixed> $pages the definition's layout, for the order
	 * @return list<array{label: string, value: string}>
	 */
	public static function answers(array $fields, array $data, array $pages = []): array {
		$order = [];
		foreach ($pages as $page) {
			foreach (is_array($page) && is_array($page['rows'] ?? null) ? $page['rows'] : [] as $row) {
				foreach (is_array($row) && is_array($row['cells'] ?? null) ? $row['cells'] : [] as $cell) {
					if (is_array($cell) && is_string($cell['field'] ?? null)) {
						$order[$cell['field']] = true;
					}
				}
			}
		}
		// A field the layout does not mention still counts, after the others.
		foreach (array_keys($fields) as $id) {
			$order[(string)$id] = true;
		}

		$answers = [];
		foreach (array_keys($order) as $id) {
			$id = (string)$id;
			$field = $fields[$id] ?? null;
			$value = $data[$id] ?? null;
			if (!is_array($field) || !FieldType::isInput((string)($field['type'] ?? '')) || $value === null || $value === '' || $value === []) {
				continue;
			}
			$text = self::display($field, $value);
			if (trim($text) === '') {
				continue;
			}
			$label = is_string($field['label'] ?? null) && trim($field['label']) !== '' ? trim($field['label']) : $id;
			$answers[] = ['label' => $label, 'value' => $text];
		}
		return $answers;
	}

	/**
	 * Every answer as plain text: `Label: answer`, with an answer of several lines indented under its label.
	 *
	 * @param list<array{label: string, value: string}> $answers
	 */
	public static function answersAsText(array $answers): string {
		$lines = [];
		foreach ($answers as $answer) {
			$value = str_replace(["\r\n", "\r"], "\n", $answer['value']);
			$lines[] = str_contains($value, "\n")
				? $answer['label'] . ":\n  " . str_replace("\n", "\n  ", $value)
				: $answer['label'] . ': ' . $value;
		}
		return implode("\n", $lines);
	}

	/**
	 * An email body as HTML, for mail programs that show it: the same text with its line breaks, and
	 * `{all_fields}` as a table of labels and answers. Everything a person typed is escaped, so an
	 * answer can never add markup.
	 *
	 * @param array<string, array<string, mixed>> $fields
	 * @param array<string, mixed> $data
	 * @param list<array{label: string, value: string}> $answers
	 */
	public static function renderHtml(string $template, array $fields, array $data, array $answers): string {
		$parts = preg_split('/(\{[^{}]*\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
		$html = '';
		foreach ($parts === false ? [$template] : $parts as $part) {
			$id = strlen($part) >= 2 && $part[0] === '{' && $part[-1] === '}' ? substr($part, 1, -1) : null;
			if ($id !== null && isset($fields[$id])) {
				$html .= self::escape(self::display($fields[$id], $data[$id] ?? null));
			} elseif ($id === self::ALL_FIELDS) {
				$html .= self::answersAsTable($answers);
			} else {
				$html .= self::escape($part);
			}
		}
		return '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#222222">' . $html . '</div>';
	}

	/**
	 * @param list<array{label: string, value: string}> $answers
	 */
	private static function answersAsTable(array $answers): string {
		if ($answers === []) {
			return '';
		}
		$rows = '';
		foreach ($answers as $answer) {
			$rows .= '<tr>'
				. '<th align="left" valign="top" style="padding:8px 16px 8px 0;border-bottom:1px solid #e5e5e5;font-weight:600;text-align:left;width:35%">' . self::escape($answer['label']) . '</th>'
				. '<td valign="top" style="padding:8px 0;border-bottom:1px solid #e5e5e5">' . self::escape($answer['value']) . '</td>'
				. '</tr>';
		}
		return '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:640px;margin:8px 0">' . $rows . '</table>';
	}

	private static function escape(string $text): string {
		return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'), false);
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
		if (($field['type'] ?? null) === FieldType::DATETIME && is_string($value)) {
			// Stored with a T between the date and the time; people read a space.
			return str_replace('T', ' ', $value);
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
