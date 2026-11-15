<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Db\Entry;
use OCA\Webforms\Db\EntryMapper;
use OCA\Webforms\Db\VersionMapper;
use OCA\Webforms\Forms\EntryFilter;
use OCA\Webforms\Rules\RuleEngine;

/**
 * Finds the responses of a form that match an EntryFilter (docs/SCHEMA.md section 17). A filter the
 * database can answer (dates, respondent, order) is a plain query. Free text and answer conditions
 * need each response read against its own version of the form, so those scan the form's responses in
 * order and stop at MAX_SCAN. The answer says when that happened, because the count is then a lower
 * bound.
 */
class EntryQueryService {
	public const MAX_SCAN = 20000;
	private const BATCH = 200;

	public function __construct(
		private readonly EntryMapper $entries,
		private readonly VersionMapper $versions,
		private readonly RuleEngine $engine,
	) {
	}

	/**
	 * @return array{entries: list<Entry>, total: int, truncated: bool}
	 */
	public function page(int $formId, EntryFilter $filter, int $limit, int $offset): array {
		if (!$filter->needsScan()) {
			return [
				'entries' => $this->entries->findFiltered($formId, $filter, $limit, $offset),
				'total' => $this->entries->countFiltered($formId, $filter),
				'truncated' => false,
			];
		}

		/** @var array<int, array<string, array<string, mixed>>> $fieldsByVersion */
		$fieldsByVersion = [];
		// Questions the form asks now, for conditions on one an older response was never asked.
		$latest = $this->versions->findLatest($formId);
		$currentFields = $latest === null ? [] : self::fieldsOf($latest->getDefinitionArray());
		$page = [];
		$matched = 0;
		$scanned = 0;
		$truncated = false;
		for ($position = 0; ; $position += self::BATCH) {
			if ($scanned >= self::MAX_SCAN) {
				// There may be more; the count so far is a lower bound.
				$truncated = $this->entries->findFiltered($formId, $filter, 1, $scanned) !== [];
				break;
			}
			$batch = $this->entries->findFiltered($formId, $filter, self::BATCH, $position);
			if ($batch === []) {
				break;
			}
			foreach ($batch as $entry) {
				$scanned++;
				$fields = $fieldsByVersion[$entry->getVersionId()] ??= self::fieldsOf($this->versions->find($entry->getVersionId())->getDefinitionArray());
				if (!$filter->matches($this->engine, $fields, $entry->getDataArray(), $entry->getUserId(), $currentFields)) {
					continue;
				}
				if ($matched >= $offset && count($page) < $limit) {
					$page[] = $entry;
				}
				$matched++;
			}
			if (count($batch) < self::BATCH) {
				break;
			}
		}
		return ['entries' => $page, 'total' => $matched, 'truncated' => $truncated];
	}

	/**
	 * @param array<string, mixed> $definition
	 * @return array<string, array<string, mixed>>
	 */
	private static function fieldsOf(array $definition): array {
		$fields = $definition['fields'] ?? [];
		return is_array($fields) ? array_filter($fields, 'is_array') : [];
	}
}
