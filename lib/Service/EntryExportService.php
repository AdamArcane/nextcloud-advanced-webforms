<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Db\EntryMapper;
use OCA\Webforms\Db\VersionMapper;
use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Forms\CsvExporter;

/**
 * Exports a form's responses as CSV.
 */
class EntryExportService {
	/** Upper bound so one request cannot hold an unbounded amount of memory. */
	public const MAX_ROWS = 20000;
	private const PAGE = 500;

	public function __construct(
		private readonly FormService $forms,
		private readonly EntryMapper $entries,
		private readonly VersionMapper $versions,
	) {
	}

	/**
	 * @return array{filename: string, csv: string}
	 * @throws NotFoundException
	 */
	public function csv(string $userId, int $formId): array {
		$form = $this->forms->get($userId, $formId);

		$rows = [];
		$definitions = [];
		for ($offset = 0; $offset < self::MAX_ROWS; $offset += self::PAGE) {
			$page = $this->entries->findByForm($form->getId(), self::PAGE, $offset);
			foreach ($page as $entry) {
				$rows[] = [
					'id' => $entry->getId(),
					'versionId' => $entry->getVersionId(),
					'userId' => $entry->getUserId(),
					'submittedAt' => $entry->getSubmittedAt(),
					'data' => $entry->getDataArray(),
				];
				$definitions[$entry->getVersionId()] ??= $this->versions->find($entry->getVersionId())->getDefinitionArray();
			}
			if (count($page) < self::PAGE) {
				break;
			}
		}

		return [
			'filename' => self::filename($form->getTitle()),
			// The query returns newest first; a spreadsheet reads better oldest first.
			'csv' => CsvExporter::build(array_reverse($rows), $definitions),
		];
	}

	/**
	 * A safe ASCII file name: the title cleaned up, plus the date.
	 */
	public static function filename(string $title): string {
		$slug = trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-');
		return ($slug === '' ? 'responses' : substr($slug, 0, 60)) . '-' . gmdate('Y-m-d') . '.csv';
	}
}
