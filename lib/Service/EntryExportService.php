<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Access\Role;
use OCA\Webforms\Db\Entry;
use OCA\Webforms\Db\VersionMapper;
use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Forms\CsvExporter;
use OCA\Webforms\Forms\EntryFilter;
use OCA\Webforms\Forms\XlsxWriter;
use OCA\Webforms\Settings\StatusSettings;

/**
 * Exports a form's responses as CSV or an Excel workbook.
 */
class EntryExportService {
	/** Upper bound so one request cannot hold an unbounded amount of memory. */
	public const MAX_ROWS = 20000;
	public const CSV = 'csv';
	public const XLSX = 'xlsx';

	public function __construct(
		private readonly FormService $forms,
		private readonly EntryQueryService $query,
		private readonly VersionMapper $versions,
		private readonly StatusSettings $statuses,
	) {
	}

	/**
	 * The responses as a file (docs/SCHEMA.md section 17.1): everything, what a filter matches, or the ones
	 * selected by id, with all the questions or only some, as CSV or an Excel workbook, oldest first.
	 *
	 * @param list<int>|null $ids
	 * @param list<string>|null $columnIds
	 * @return array{filename: string, body: string, mime: string, truncated: bool}
	 * @throws NotFoundException
	 */
	public function export(string $userId, int $formId, EntryFilter $filter, ?array $ids, ?array $columnIds, string $format): array {
		$form = $this->forms->get($userId, $formId, Role::REVIEWER);

		if ($ids !== null) {
			$entries = $this->query->byIds($form->getId(), array_slice($ids, 0, self::MAX_ROWS));
			$truncated = false;
		} else {
			$found = $this->query->all($form->getId(), $filter, self::MAX_ROWS);
			$entries = $found['entries'];
			$truncated = $found['truncated'];
		}
		// A spreadsheet reads better oldest first.
		usort($entries, static fn (Entry $a, Entry $b): int => [$a->getSubmittedAt(), $a->getId()] <=> [$b->getSubmittedAt(), $b->getId()]);

		$labels = $this->statuses->labels();
		$rows = [];
		$definitions = [];
		foreach ($entries as $entry) {
			$rows[] = [
				'id' => $entry->getId(),
				'versionId' => $entry->getVersionId(),
				'userId' => $entry->getUserId(),
				'submittedAt' => $entry->getSubmittedAt(),
				'data' => $entry->getDataArray(),
				// A status the administrator has since deleted reads as none.
				'status' => $labels[(string)$entry->getStatus()] ?? '',
			];
			$definitions[$entry->getVersionId()] ??= $this->versions->find($entry->getVersionId())->getDefinitionArray();
		}

		if ($format === self::XLSX) {
			$table = CsvExporter::table($rows, $definitions, 'Submitted (UTC)', $columnIds, true);
			return [
				'filename' => self::filename($form->getTitle(), 'xlsx'),
				'body' => XlsxWriter::build($table['header'], $table['rows'], $table['numbers']),
				'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'truncated' => $truncated,
			];
		}
		return [
			'filename' => self::filename($form->getTitle(), 'csv'),
			'body' => CsvExporter::build($rows, $definitions, 'Submitted (UTC)', $columnIds, true),
			'mime' => 'text/csv; charset=utf-8',
			'truncated' => $truncated,
		];
	}

	/**
	 * The CSV of every response, as the plain download has always been.
	 *
	 * @return array{filename: string, csv: string}
	 * @throws NotFoundException
	 */
	public function csv(string $userId, int $formId): array {
		$file = $this->export($userId, $formId, new EntryFilter(), null, null, self::CSV);
		return ['filename' => $file['filename'], 'csv' => $file['body']];
	}

	/**
	 * A safe ASCII file name: the title cleaned up, plus the date.
	 */
	public static function filename(string $title, string $extension = 'csv'): string {
		$slug = trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-');
		return ($slug === '' ? 'responses' : substr($slug, 0, 60)) . '-' . gmdate('Y-m-d') . '.' . $extension;
	}
}
