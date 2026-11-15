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

		$page = [];
		$matched = 0;
		$matches = $this->matching($formId, $filter);
		foreach ($matches as $entry) {
			if ($matched >= $offset && count($page) < $limit) {
				$page[] = $entry;
			}
			$matched++;
		}
		return ['entries' => $page, 'total' => $matched, 'truncated' => $matches->getReturn()];
	}

	/**
	 * Every matching response, for an export or a bulk delete, in the filter's order and at most $cap of them.
	 * `truncated` says there are more than that (or the search itself stopped early).
	 *
	 * @return array{entries: list<Entry>, truncated: bool}
	 */
	public function all(int $formId, EntryFilter $filter, int $cap): array {
		$found = [];
		$matches = $this->matching($formId, $filter);
		foreach ($matches as $entry) {
			if (count($found) >= $cap) {
				return ['entries' => $found, 'truncated' => true];
			}
			$found[] = $entry;
		}
		return ['entries' => $found, 'truncated' => $matches->getReturn()];
	}

	/**
	 * The responses a person selected, oldest first. Ids that are not this form's are ignored.
	 *
	 * @param list<int> $ids
	 * @return list<Entry>
	 */
	public function byIds(int $formId, array $ids): array {
		return $this->entries->findByIds($formId, $ids);
	}

	/**
	 * Walks a form's responses in the filter's order and yields the ones that match, reading each against
	 * its own version when the filter needs that. Stops after MAX_SCAN responses. The generator's return
	 * value says whether it stopped with more left.
	 *
	 * @return \Generator<int, Entry, mixed, bool>
	 */
	private function matching(int $formId, EntryFilter $filter): \Generator {
		$scan = $filter->needsScan();
		/** @var array<int, array<string, array<string, mixed>>> $fieldsByVersion */
		$fieldsByVersion = [];
		$currentFields = [];
		if ($scan) {
			// Questions the form asks now, for conditions on one an older response was never asked.
			$latest = $this->versions->findLatest($formId);
			$currentFields = $latest === null ? [] : self::fieldsOf($latest->getDefinitionArray());
		}

		$scanned = 0;
		for ($position = 0; ; $position += self::BATCH) {
			if ($scanned >= self::MAX_SCAN) {
				// There may be more; what was found so far is a lower bound.
				return $this->entries->findFiltered($formId, $filter, 1, $scanned) !== [];
			}
			$batch = $this->entries->findFiltered($formId, $filter, self::BATCH, $position);
			foreach ($batch as $entry) {
				$scanned++;
				if ($scan) {
					$fields = $fieldsByVersion[$entry->getVersionId()] ??= self::fieldsOf($this->versions->find($entry->getVersionId())->getDefinitionArray());
					if (!$filter->matches($this->engine, $fields, $entry->getDataArray(), $entry->getUserId(), $currentFields)) {
						continue;
					}
				}
				yield $entry;
			}
			if (count($batch) < self::BATCH) {
				return false;
			}
		}
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
