<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

use OCA\Webforms\Actions\Template;
use OCA\Webforms\Rules\FieldType;

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
	 * @param list<string>|null $columnIds only these questions, in form order. Null means every question.
	 */
	public static function build(array $entries, array $definitions, string $submittedHeader = 'Submitted (UTC)', ?array $columnIds = null): string {
		$table = self::table($entries, $definitions, $submittedHeader, $columnIds);

		$lines = [implode(',', array_map(static fn (string $cell): string => self::quote(self::neutralize($cell)), $table['header']))];
		foreach ($table['rows'] as $rowIndex => $row) {
			$cells = [];
			foreach ($row as $columnIndex => $text) {
				// A number is data, so a negative one must stay a number rather than be treated as text.
				$cells[] = self::quote($table['numbers'][$rowIndex][$columnIndex] !== null ? $text : self::neutralize($text));
			}
			$lines[] = implode(',', $cells);
		}
		return self::BOM . implode("\r\n", $lines) . "\r\n";
	}

	/**
	 * The responses as a table, before it is written as CSV or a workbook: a header, and for each response
	 * its cells as text (not made safe for any one format) with, alongside, the number a cell holds or null.
	 *
	 * @param list<array{id: int, versionId: int, userId: string|null, submittedAt: int, data: array<string, mixed>}> $entries oldest first
	 * @param array<int, array<string, mixed>> $definitions
	 * @param list<string>|null $columnIds
	 * @return array{header: list<string>, rows: list<list<string>>, numbers: list<list<int|float|null>>}
	 */
	public static function table(array $entries, array $definitions, string $submittedHeader = 'Submitted (UTC)', ?array $columnIds = null): array {
		$columns = self::columns($definitions);
		if ($columnIds !== null) {
			$wanted = array_flip($columnIds);
			$columns = array_values(array_filter($columns, static fn (array $column): bool => isset($wanted[$column['id']])));
		}

		$header = array_merge(['Response ID', $submittedHeader, 'Respondent'], array_column($columns, 'header'));
		$rows = [];
		$numbers = [];
		foreach ($entries as $entry) {
			$fields = is_array($definitions[$entry['versionId']]['fields'] ?? null) ? $definitions[$entry['versionId']]['fields'] : [];
			$row = [(string)$entry['id'], gmdate('Y-m-d H:i:s', $entry['submittedAt']), $entry['userId'] ?? ''];
			$numberRow = [$entry['id'], null, null];
			foreach ($columns as $column) {
				$value = $entry['data'][$column['id']] ?? null;
				$field = $fields[$column['id']] ?? null;
				$row[] = is_array($field) && $value !== null ? Template::display($field, $value) : '';
				$numberRow[] = is_array($field) && (is_int($value) || is_float($value)) ? $value : null;
			}
			$rows[] = $row;
			$numbers[] = $numberRow;
		}
		return ['header' => $header, 'rows' => $rows, 'numbers' => $numbers];
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
			$columns[] = ['id' => (string)$id, 'header' => $counts[$label] > 1 ? $label . ' (' . $id . ')' : $label];
		}
		return $columns;
	}

	/**
	 * Field ids as they appear on the form, top to bottom, then any not placed.
	 *
	 * @param array<string, mixed> $definition
	 * @return list<string>
	 */
	public static function layoutOrder(array $definition): array {
		$order = [];
		$pages = $definition['pages'] ?? [];
		foreach (is_array($pages) ? $pages : [] as $page) {
			$rows = is_array($page) ? ($page['rows'] ?? []) : [];
			foreach (is_array($rows) ? $rows : [] as $row) {
				foreach (is_array($row['cells'] ?? null) ? $row['cells'] : [] as $cell) {
					if (is_array($cell) && is_string($cell['field'] ?? null)) {
						$order[] = $cell['field'];
					}
				}
			}
		}
		foreach (array_keys(is_array($definition['fields'] ?? null) ? $definition['fields'] : []) as $id) {
			$order[] = (string)$id;
		}
		return array_values(array_unique($order));
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
