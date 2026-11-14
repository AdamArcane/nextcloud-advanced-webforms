<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Forms;

use OCA\AdvancedForms\Actions\Template;
use OCA\AdvancedForms\Rules\FieldType;

/**
 * Builds a CSV of a form's responses.
 *
 * A form's fields can change between versions, so the columns are the union of
 * the input fields of every version that has responses, newest version first,
 * and each response is read through its own version (docs/SCHEMA.md section 8):
 * a renamed option still shows the label it had when it was chosen.
 */
final class CsvExporter {
	/** Lets Excel recognise the file as UTF-8. */
	private const BOM = "\xEF\xBB\xBF";

	/**
	 * @param list<array{id: int, versionId: int, userId: string|null, submittedAt: int, data: array<string, mixed>}> $entries oldest first
	 * @param array<int, array<string, mixed>> $definitions form definitions keyed by version id
	 */
	public static function build(array $entries, array $definitions, string $submittedHeader = 'Submitted (UTC)'): string {
		$columns = self::columns($definitions);

		$rows = [array_merge(['Response ID', $submittedHeader, 'Respondent'], array_column($columns, 'header'))];
		foreach ($entries as $entry) {
			$fields = is_array($definitions[$entry['versionId']]['fields'] ?? null) ? $definitions[$entry['versionId']]['fields'] : [];
			$row = [(string)$entry['id'], gmdate('Y-m-d H:i:s', $entry['submittedAt']), $entry['userId'] ?? ''];
			foreach ($columns as $column) {
				$value = $entry['data'][$column['id']] ?? null;
				$field = $fields[$column['id']] ?? null;
				$row[] = is_array($field) && $value !== null ? self::cell($field, $value) : '';
			}
			$rows[] = $row;
		}

		$lines = array_map(static fn (array $row): string => implode(',', array_map(self::quote(...), $row)), $rows);
		return self::BOM . implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * @param array<int, array<string, mixed>> $definitions
	 * @return list<array{id: string, header: string}>
	 */
	private static function columns(array $definitions): array {
		krsort($definitions);
		$labels = [];
		foreach ($definitions as $definition) {
			$fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
			foreach (self::layoutOrder($definition) as $id) {
				$field = $fields[$id] ?? null;
				if (is_array($field) && FieldType::isInput((string)($field['type'] ?? '')) && !isset($labels[$id])) {
					$labels[$id] = (string)($field['label'] ?? $id);
				}
			}
		}

		// Two different fields can share a label (across versions); keep the columns telling apart.
		$counts = array_count_values($labels);
		$columns = [];
		foreach ($labels as $id => $label) {
			$columns[] = ['id' => (string)$id, 'header' => self::neutralize($counts[$label] > 1 ? $label . ' (' . $id . ')' : $label)];
		}
		return $columns;
	}

	/**
	 * Field ids as they appear on the form, top to bottom, then any not placed.
	 *
	 * @param array<string, mixed> $definition
	 * @return list<string>
	 */
	private static function layoutOrder(array $definition): array {
		$order = [];
		$rows = $definition['pages'][0]['rows'] ?? [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			foreach (is_array($row['cells'] ?? null) ? $row['cells'] : [] as $cell) {
				if (is_array($cell) && is_string($cell['field'] ?? null)) {
					$order[] = $cell['field'];
				}
			}
		}
		foreach (array_keys(is_array($definition['fields'] ?? null) ? $definition['fields'] : []) as $id) {
			$order[] = (string)$id;
		}
		return array_values(array_unique($order));
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private static function cell(array $field, mixed $value): string {
		$text = Template::display($field, $value);
		// Numbers are data, so a negative one must stay a number rather than be treated as text.
		return is_int($value) || is_float($value) ? $text : self::neutralize($text);
	}

	/**
	 * Spreadsheet programs run a cell that starts with = + - @ (or a tab or
	 * carriage return) as a formula, and a response is text anyone can submit.
	 * A leading apostrophe makes it plain text (OWASP "CSV injection").
	 */
	public static function neutralize(string $text): string {
		return $text !== '' && strpbrk($text[0], "=+-@\t\r") !== false ? "'" . $text : $text;
	}

	private static function quote(string $value): string {
		if ($value === '' || strpbrk($value, "\",\r\n") === false) {
			return $value;
		}
		return '"' . str_replace('"', '""', $value) . '"';
	}
}
