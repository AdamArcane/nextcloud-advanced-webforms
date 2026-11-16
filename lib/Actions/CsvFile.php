<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions;

use OCA\Webforms\Forms\CsvExporter;
use OCA\Webforms\Forms\UploadPolicy;

/**
 * The CSV file a `files` action adds responses to (docs/SCHEMA.md section 19): which paths are
 * acceptable, and how one response is added to what the file holds. Pure, so it is tested without
 * a server.
 */
final class CsvFile {
	public const BOM = "\xEF\xBB\xBF";

	/** A file path like a folder path (UploadPolicy::cleanFolder) whose name ends in `.csv`, or null. */
	public static function cleanPath(string $path): ?string {
		$clean = UploadPolicy::cleanFolder($path);
		if ($clean === null) {
			return null;
		}
		$name = basename($clean);
		return strlen($name) > 4 && strtolower(substr($name, -4)) === '.csv' ? $clean : null;
	}

	/**
	 * The file's new contents with one response added.
	 *
	 * A new or empty file gets a header row. An existing file keeps its own: columns are matched to it
	 * by heading, and headings it does not have yet are added at the end of its header row.
	 *
	 * @param string|null $existing what the file holds now, null when there is no file
	 * @param list<string> $header the headings as the export makes them, not yet made safe
	 * @param list<string> $cells the response's cells, in the same order
	 * @param list<int|float|null> $numbers the number each cell holds, or null (numbers are not neutralized)
	 */
	public static function append(?string $existing, array $header, array $cells, array $numbers): string {
		$wanted = array_map(static fn (string $heading): string => CsvExporter::neutralize($heading), $header);
		$encoded = [];
		foreach ($cells as $index => $text) {
			$encoded[] = CsvExporter::quote(($numbers[$index] ?? null) !== null ? $text : CsvExporter::neutralize($text));
		}

		$hasBom = $existing !== null && str_starts_with($existing, self::BOM);
		$body = $hasBom ? substr((string)$existing, strlen(self::BOM)) : (string)$existing;
		if (trim($body) === '') {
			$headerLine = implode(',', array_map(CsvExporter::quote(...), $wanted));
			return self::BOM . $headerLine . "\r\n" . implode(',', $encoded) . "\r\n";
		}

		[$heads, $headerEnd] = self::firstRecord($body);
		$width = count($heads);
		$row = array_fill(0, $width, '');
		$used = [];
		$added = false;
		foreach ($wanted as $index => $heading) {
			$column = null;
			foreach ($heads as $position => $existingHeading) {
				if (!isset($used[$position]) && trim($existingHeading) === trim($heading)) {
					$column = $position;
					break;
				}
			}
			if ($column === null) {
				// A heading the file does not have yet: it goes at the end of the header row.
				$column = count($heads);
				$heads[] = $heading;
				$row[] = '';
				$added = true;
			}
			$used[$column] = true;
			$row[$column] = $encoded[$index] ?? '';
		}

		$rest = substr($body, $headerEnd);
		if ($added) {
			$body = implode(',', array_map(CsvExporter::quote(...), $heads)) . "\r\n" . $rest;
		} elseif (!str_ends_with($body, "\n")) {
			$body .= "\r\n";
		}
		if ($added && $rest !== '' && !str_ends_with($rest, "\n")) {
			$body .= "\r\n";
		}
		return ($hasBom ? self::BOM : '') . $body . implode(',', $row) . "\r\n";
	}

	/**
	 * The first CSV record and the byte offset just after it, so quoted line breaks in a heading are
	 * read correctly.
	 *
	 * @return array{0: list<string>, 1: int}
	 */
	private static function firstRecord(string $body): array {
		$stream = fopen('php://memory', 'r+b');
		if ($stream === false) {
			throw new \RuntimeException('no memory stream');
		}
		fwrite($stream, $body);
		rewind($stream);
		$record = fgetcsv($stream, null, ',', '"', '');
		$end = ftell($stream);
		fclose($stream);
		$heads = is_array($record) ? array_map(static fn (?string $cell): string => (string)$cell, $record) : [];
		return [array_values($heads), $end === false ? strlen($body) : $end];
	}
}
