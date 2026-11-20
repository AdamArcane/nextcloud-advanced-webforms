<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Access\Role;
use OCA\Webforms\Db\EntryMapper;
use OCA\Webforms\Db\VersionMapper;
use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Forms\ApiShape;
use OCA\Webforms\Forms\EntryFilter;
use OCA\Webforms\Forms\JsonShape;
use OCA\Webforms\Forms\ReadingCopy;

/**
 * What the read-only API reads (docs/SCHEMA.md section 23). It only reads, and every method that names a
 * form asks FormService for at least the Reviewer role first, as PermissionTableTest checks. A form the
 * caller cannot open is not found, so the API does not say whether it exists.
 */
final class ApiService {
	public const MAX_LIMIT = 200;

	public function __construct(
		private readonly FormService $forms,
		private readonly EntryMapper $entries,
		private readonly VersionMapper $versions,
		private readonly EntryQueryService $query,
		private readonly EntryExportService $export,
	) {
	}

	/**
	 * The forms the caller can open.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forms(string $userId): array {
		$described = $this->forms->listForDisplay($userId);
		$ids = array_map(static fn (array $form): int => (int)$form['id'], $described);
		$counts = $ids === [] ? [] : $this->entries->countByForms($ids);
		$result = [];
		foreach ($described as $form) {
			$result[] = ApiShape::form($form, $counts[(int)$form['id']] ?? 0);
		}
		return $result;
	}

	/**
	 * One form with the questions of its latest published version (none until it has been published).
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	public function form(string $userId, int $id): array {
		$form = $this->forms->get($userId, $id, Role::REVIEWER);
		$latestId = $form->getLatestVersionId();
		$result = ApiShape::form($this->forms->toArray($form, $userId), $this->entries->countByForm($form->getId()));
		$result['questions'] = $latestId === null ? null : $this->questions($this->versions->find($latestId)->getDefinitionArray());
		return $result;
	}

	/**
	 * The published versions of a form, oldest first.
	 *
	 * @return list<array{id: int, number: int, publishedAt: int}>
	 * @throws NotFoundException
	 */
	public function versions(string $userId, int $id): array {
		$form = $this->forms->get($userId, $id, Role::REVIEWER);
		$result = [];
		foreach ($this->versions->findByForm($form->getId()) as $version) {
			$result[] = ['id' => $version->getId(), 'number' => $version->getVersionNumber(), 'publishedAt' => $version->getCreatedAt()];
		}
		return $result;
	}

	/**
	 * One version's questions. A version of another form is not found.
	 *
	 * @return array{id: int, number: int, publishedAt: int, questions: array<mixed>|\stdClass}
	 * @throws NotFoundException
	 */
	public function version(string $userId, int $id, int $versionId): array {
		$form = $this->forms->get($userId, $id, Role::REVIEWER);
		$version = $this->versions->find($versionId);
		if ($version->getFormId() !== $form->getId()) {
			throw new NotFoundException();
		}
		return [
			'id' => $version->getId(),
			'number' => $version->getVersionNumber(),
			'publishedAt' => $version->getCreatedAt(),
			'questions' => $this->questions($version->getDefinitionArray()),
		];
	}

	/**
	 * A page of responses. With `after` set the filter's order is oldest first (the caller sets it) and
	 * `nextAfter` is the id to send next time.
	 *
	 * @return array{total: int, truncated: bool, responses: list<array<string, mixed>>, nextAfter: int|null}
	 * @throws NotFoundException
	 */
	public function responses(string $userId, int $id, int $limit, EntryFilter $filter): array {
		$form = $this->forms->get($userId, $id, Role::REVIEWER);
		$found = $this->query->page($form->getId(), $filter, max(1, min($limit, self::MAX_LIMIT)), 0);
		/** @var array<int, array<string, array<string, mixed>>> $fields */
		$fields = [];
		$responses = [];
		foreach ($found['entries'] as $entry) {
			$versionId = $entry->getVersionId();
			$fields[$versionId] ??= self::fieldsOf($this->versions->find($versionId)->getDefinitionArray());
			$responses[] = ApiShape::response($entry->getId(), $versionId, $entry->getUserId(), $entry->getSubmittedAt(), $entry->getStatus(), $entry->getDataArray(), $fields[$versionId]);
		}
		return [
			'total' => $found['total'],
			'truncated' => $found['truncated'],
			'responses' => $responses,
			'nextAfter' => $responses === [] ? null : max(array_column($responses, 'id')),
		];
	}

	/**
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	public function response(string $userId, int $id, int $responseId): array {
		$form = $this->forms->get($userId, $id, Role::REVIEWER);
		$entry = $this->entries->findInForm($form->getId(), $responseId);
		$fields = self::fieldsOf($this->versions->find($entry->getVersionId())->getDefinitionArray());
		return ApiShape::response($entry->getId(), $entry->getVersionId(), $entry->getUserId(), $entry->getSubmittedAt(), $entry->getStatus(), $entry->getDataArray(), $fields);
	}

	/**
	 * Counts and ranges over the responses a filter matches (docs/SCHEMA.md section 17.2).
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	public function summary(string $userId, int $id, EntryFilter $filter): array {
		$this->forms->get($userId, $id, Role::REVIEWER);
		return $this->forms->summarizeEntries($userId, $id, $filter);
	}

	/**
	 * The responses a filter matches as a CSV file (docs/SCHEMA.md section 9.1).
	 *
	 * @return array{filename: string, body: string, mime: string, truncated: bool}
	 * @throws NotFoundException
	 */
	public function csv(string $userId, int $id, EntryFilter $filter): array {
		$this->forms->get($userId, $id, Role::REVIEWER);
		return $this->export->export($userId, $id, $filter, null, null, EntryExportService::CSV);
	}

	/**
	 * @param array<string, mixed> $definition
	 * @return array<mixed>|\stdClass
	 */
	private function questions(array $definition): array|\stdClass {
		return JsonShape::definition(ReadingCopy::of($definition));
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
