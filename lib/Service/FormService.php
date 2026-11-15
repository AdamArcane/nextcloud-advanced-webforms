<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Actions\Adapter\NextcloudSecrets;
use OCA\Webforms\Actions\Ports\TablesFailure;
use OCA\Webforms\Actions\Ports\TablesPort;
use OCA\Webforms\Actions\TablesValue;
use OCA\Webforms\Db\ActionLogMapper;
use OCA\Webforms\Event\EntryDeletedEvent;
use OCA\Webforms\Event\EventPayload;
use OCA\Webforms\Event\EventPublisher;
use OCA\Webforms\Event\FormPublishedEvent;
use OCA\Webforms\Event\FormStatusChangedEvent;
use OCA\Webforms\Db\Entry;
use OCA\Webforms\Db\EntryMapper;
use OCA\Webforms\Db\Form;
use OCA\Webforms\Forms\CsvExporter;
use OCA\Webforms\Forms\EntryFilter;
use OCA\Webforms\Forms\EntrySummary;
use OCA\Webforms\Db\FormMapper;
use OCA\Webforms\Db\Version;
use OCA\Webforms\Db\VersionMapper;
use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Forms\UploadPolicy;
use OCA\Webforms\Rules\FieldType;
use OCA\Webforms\Uploads\UploadRejected;
use OCA\Webforms\Uploads\UploadService;
use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Forms\DefinitionValidator;
use OCA\Webforms\Forms\FormPackage;
use OCA\Webforms\Forms\JsonShape;
use OCA\Webforms\Forms\RetiredIds;
use OCA\Webforms\Forms\Availability;
use OCA\Webforms\Forms\ShareSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;

/**
 * Owner-side form management: drafts, publishing, status, entries
 * (docs/SCHEMA.md section 8). Every method takes the acting user id and treats
 * forms owned by someone else as missing.
 */
class FormService {
	public const MAX_DEFINITION_BYTES = 512 * 1024;
	public const MAX_PAGE_SIZE = 100;
	/** Selected responses in one bulk delete (they travel in the request). */
	public const MAX_BULK_IDS = 500;
	/** Responses one filtered bulk delete may remove. */
	public const MAX_BULK_MATCHES = 5000;

	public function __construct(
		private readonly FormMapper $forms,
		private readonly VersionMapper $versions,
		private readonly EntryMapper $entries,
		private readonly ActionLogMapper $actionLog,
		private readonly DefinitionValidator $validator,
		private readonly ITimeFactory $time,
		private readonly ISecureRandom $random,
		private readonly NextcloudSecrets $secrets,
		private readonly UploadService $uploads,
		private readonly EventPublisher $events,
		private readonly TablesPort $tables,
		private readonly PolicyService $policy,
		private readonly EntryQueryService $query,
	) {
	}

	/**
	 * @return list<Form>
	 */
	public function list(string $userId): array {
		return $this->forms->findByOwner($userId);
	}

	/**
	 * The owner's forms as the list shows them, each with whether its draft differs from what is
	 * published. The published versions are read in one query, not one per form.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listForDisplay(string $userId): array {
		$forms = $this->list($userId);
		$versionIds = [];
		foreach ($forms as $form) {
			if ($form->getLatestVersionId() !== null) {
				$versionIds[] = $form->getLatestVersionId();
			}
		}
		$versions = $this->versions->findByIds($versionIds);
		$counts = $this->entries->countByForms(array_map(static fn (Form $form): int => $form->getId(), $forms));
		$result = [];
		foreach ($forms as $form) {
			$latest = $versions[$form->getLatestVersionId() ?? 0] ?? null;
			$data = $this->describe($form, $latest, $counts[$form->getId()] ?? 0);
			$data['hasUnpublishedChanges'] = $latest === null ? true : $this->differs($form, $latest);
			$result[] = $data;
		}
		return $result;
	}

	/**
	 * @throws NotFoundException
	 */
	public function get(string $userId, int $id): Form {
		$form = $this->forms->find($id);
		if ($form->getOwnerId() !== $userId) {
			throw new NotFoundException();
		}
		return $form;
	}

	public function create(string $userId, string $title): Form {
		if (!$this->policy->canCreate($userId)) {
			// Existing forms keep working: only making new ones (or importing) is limited to the creator groups.
			throw new ValidationException([['code' => 'create_not_allowed', 'path' => '']]);
		}
		$title = trim($title);
		if ($title === '' || mb_strlen($title) > 255) {
			throw new ValidationException([['code' => 'title_invalid', 'path' => 'title']]);
		}

		$now = $this->time->getTime();
		$form = new Form();
		$form->setOwnerId($userId);
		$form->setTitle($title);
		$form->setDraft($this->encode([
			'schemaVersion' => DefinitionValidator::SCHEMA_VERSION,
			'title' => $title,
			'settings' => new \stdClass(),
			'fields' => new \stdClass(),
			'pages' => [['id' => 'p_1', 'title' => null, 'rows' => []]],
			'rules' => [],
			'actions' => [],
		]));
		$form->setRetiredIds('[]');
		$form->setStatus(Form::STATUS_UNPUBLISHED);
		$form->setCreatedAt($now);
		$form->setUpdatedAt($now);
		return $this->forms->insert($form);
	}

	/**
	 * The form as an export file (docs/SCHEMA.md section 13): its draft, or its latest published version.
	 *
	 * @return array{package: array<string, mixed>, filename: string}
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function exportForm(string $userId, int $id, string $source): array {
		$form = $this->get($userId, $id);
		if ($source === FormPackage::SOURCE_PUBLISHED) {
			$latestId = $form->getLatestVersionId();
			if ($latestId === null) {
				throw new ValidationException([['code' => 'not_published_yet', 'path' => 'source']]);
			}
			$definition = $this->versions->find($latestId)->getDefinitionArray();
		} elseif ($source === FormPackage::SOURCE_DRAFT) {
			$definition = $form->getDraftDefinition();
		} else {
			throw new ValidationException([['code' => 'source_invalid', 'path' => 'source']]);
		}

		$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($form->getTitle())), '-');
		return [
			'package' => FormPackage::build($definition, $source, $this->time->getTime()),
			'filename' => ($slug !== '' ? $slug : 'form') . '.webforms.json',
		];
	}

	/**
	 * Create a new draft form from an export file. It never touches an existing form. The definition goes
	 * through the normal draft save, so problems come back as warnings and publishing stays the gate.
	 *
	 * @param array<string, mixed> $package
	 * @return array{form: Form, errors: list<array{code: string, path: string}>}
	 * @throws ValidationException
	 */
	public function importForm(string $userId, array $package): array {
		$definition = FormPackage::parse($package);
		$title = $definition['title'] ?? null;
		if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 255) {
			throw new ValidationException([['code' => 'title_invalid', 'path' => 'definition.title']]);
		}

		$form = $this->create($userId, trim($title));
		try {
			return $this->saveDraft($userId, $form->getId(), $definition);
		} catch (ValidationException $e) {
			// Nothing half-made is left behind for a file that is refused.
			$this->forms->delete($form);
			throw $e;
		}
	}

	/**
	 * Store a new draft. Drafts may be incomplete, so structural problems are
	 * returned as warnings and only publishing enforces validity. A few things
	 * are refused outright because they would corrupt stored data: oversize
	 * documents, a missing title, and reusing the id of a deleted field.
	 *
	 * @param array<string, mixed> $definition
	 * @return array{form: Form, errors: list<array{code: string, path: string}>}
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function saveDraft(string $userId, int $id, array $definition): array {
		$form = $this->get($userId, $id);

		$encoded = $this->encode(JsonShape::definition($definition));
		if (strlen($encoded) > self::MAX_DEFINITION_BYTES) {
			throw new ValidationException([['code' => 'too_large', 'path' => '']]);
		}
		$title = $definition['title'] ?? null;
		if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 255) {
			throw new ValidationException([['code' => 'title_invalid', 'path' => 'title']]);
		}
		$fields = $definition['fields'] ?? null;
		if (!is_array($fields) || ($fields !== [] && array_is_list($fields))) {
			throw new ValidationException([['code' => 'fields_invalid', 'path' => 'fields']]);
		}

		// Field ids are never reused: removing a field retires its id.
		$retired = $form->getRetiredIdList();
		$errors = [];
		foreach (array_keys($fields) as $fieldId) {
			if (in_array((string)$fieldId, $retired, true)) {
				$errors[] = ['code' => 'field_id_retired', 'path' => 'fields.' . $fieldId];
			}
		}
		if ($errors !== []) {
			throw new ValidationException($errors);
		}
		$retired = RetiredIds::after(
			$retired,
			array_map('strval', array_keys($form->getDraftDefinition()['fields'] ?? [])),
			array_map('strval', array_keys($fields)),
			$this->versions->publishedFieldIds($form->getId()),
		);

		$form->setTitle(trim($title));
		$description = $definition['description'] ?? null;
		$form->setDescription(is_string($description) ? $description : null);
		$form->setDraft($encoded);
		$form->setRetiredIds($this->encode($retired));
		$form->setUpdatedAt($this->time->getTime());
		$form = $this->forms->update($form);

		return ['form' => $form, 'errors' => array_merge($this->validator->validate($definition), $this->uploadPermissionErrors($form, $definition), $this->policy->actionErrors($definition))];
	}

	/**
	 * Validate the draft strictly and store it as the next immutable version.
	 * Publishing an unchanged draft does not create a duplicate version.
	 *
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function publish(string $userId, int $id): Form {
		$form = $this->get($userId, $id);
		$this->refuseIfArchived($form);
		$draft = $form->getDraftDefinition();

		$errors = array_merge($this->validator->validate($draft), $this->uploadPermissionErrors($form, $draft), $this->tablesErrors($form, $draft), $this->policy->actionErrors($draft));
		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$encoded = $this->encode(JsonShape::definition($draft));
		$latest = $this->versions->findLatest($form->getId());
		$newVersion = false;
		if ($latest === null || $this->canonical($latest->getDefinition()) !== $this->canonical($encoded)) {
			$newVersion = true;
			$version = new Version();
			$version->setFormId($form->getId());
			$version->setVersionNumber(($latest?->getVersionNumber() ?? 0) + 1);
			$version->setSchemaVersion(DefinitionValidator::SCHEMA_VERSION);
			$version->setDefinition($encoded);
			$version->setCreatedAt($this->time->getTime());
			$latest = $this->versions->insert($version);
		}

		$form->setLatestVersionId($latest->getId());
		$form->setStatus(Form::STATUS_PUBLISHED);
		if ($form->getPublicToken() === null) {
			// 36^32 possibilities, so the link cannot be guessed.
			$form->setPublicToken($this->random->generate(32, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
		}
		$form->setUpdatedAt($this->time->getTime());
		$form = $this->forms->update($form);
		$this->events->publish(static fn (): FormPublishedEvent => new FormPublishedEvent(EventPayload::formPublished(
			$form->getId(),
			$form->getTitle(),
			$form->getOwnerId(),
			$latest->getId(),
			$latest->getVersionNumber(),
			$newVersion,
			$form->getPublicToken(),
		)));
		return $form;
	}

	/**
	 * Change how a form is shared (docs/SCHEMA.md section 18): its custom address and whether other
	 * websites may frame it. These belong to the form, so they take effect at once, without publishing.
	 *
	 * @param string|null $slug the custom address, or null (or empty) for none
	 * @param array<mixed> $embedOrigins origins allowed to frame the form; empty means any site
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function setShare(string $userId, int $id, ?string $slug, bool $embed, array $embedOrigins): Form {
		$form = $this->get($userId, $id);
		$slug = $slug === null ? null : strtolower(trim($slug));
		if ($slug === '') {
			$slug = null;
		}
		$errors = [];
		if ($slug !== null && $slug !== $form->getSlug()) {
			$code = ShareSettings::slugError($slug);
			if ($code === null && $this->forms->addressTaken($slug, $form->getId())) {
				$code = 'slug_taken';
			}
			if ($code !== null) {
				$errors[] = ['code' => $code, 'path' => 'slug'];
			}
		}
		$origins = ShareSettings::normalizeOrigins($embedOrigins);
		if ($origins === null) {
			$errors[] = ['code' => 'embed_origins_invalid', 'path' => 'embedOrigins'];
		}
		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$form->setSlug($slug);
		$form->setEmbed($embed ? 1 : 0);
		$form->setEmbedOrigins($origins === [] ? null : $this->encode($origins));
		$form->setUpdatedAt($this->time->getTime());
		try {
			return $this->forms->update($form);
		} catch (\OCP\DB\Exception $e) {
			// Two people asked for the same address at the same moment, and the other one got it.
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new ValidationException([['code' => 'slug_taken', 'path' => 'slug']]);
			}
			throw $e;
		}
	}

	/**
	 * Unpublish (0), reopen (1), or close (2) a form. Reopening needs at least
	 * one published version.
	 *
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function setStatus(string $userId, int $id, int $status): Form {
		$form = $this->get($userId, $id);
		$this->refuseIfArchived($form);
		if (!in_array($status, [Form::STATUS_UNPUBLISHED, Form::STATUS_PUBLISHED, Form::STATUS_CLOSED], true)) {
			throw new ValidationException([['code' => 'status_invalid', 'path' => 'status']]);
		}
		if ($status !== Form::STATUS_UNPUBLISHED && $form->getLatestVersionId() === null) {
			throw new ValidationException([['code' => 'not_published_yet', 'path' => 'status']]);
		}
		$previous = $form->getStatus();
		$form->setStatus($status);
		$form->setUpdatedAt($this->time->getTime());
		$form = $this->forms->update($form);
		if ($previous !== $status) {
			$this->events->publish(static fn (): FormStatusChangedEvent => new FormStatusChangedEvent(EventPayload::formStatusChanged(
				$form->getId(),
				$form->getTitle(),
				$form->getOwnerId(),
				$status,
				$previous,
			)));
		}
		return $form;
	}

	/**
	 * @throws ValidationException
	 */
	private function refuseIfArchived(Form $form): void {
		if ($form->isArchived()) {
			throw new ValidationException([['code' => 'form_archived']]);
		}
	}

	/**
	 * Archive a form or bring it back. An archived form stops taking responses (its public page says
	 * it is closed) but keeps its status, responses and link, so restoring it changes nothing else.
	 *
	 * @throws NotFoundException
	 */
	public function setArchived(string $userId, int $id, bool $archived): Form {
		$form = $this->get($userId, $id);
		if ($form->isArchived() === $archived) {
			return $form;
		}
		$form->setArchivedAt($archived ? $this->time->getTime() : null);
		$form->setUpdatedAt($this->time->getTime());
		return $this->forms->update($form);
	}

	/**
	 * Deletes the form with its versions, entries and action log. There are no
	 * database foreign keys, so this is the only cascade.
	 *
	 * @throws NotFoundException
	 */
	public function delete(string $userId, int $id): void {
		$form = $this->get($userId, $id);
		$this->deleteUploadedFiles($form);
		$this->entries->deleteByForm($form->getId());
		$this->actionLog->deleteByForm($form->getId());
		$this->secrets->deleteForForm($form->getId());
		$this->versions->deleteByForm($form->getId());
		$this->forms->delete($form);
	}

	/**
	 * @return array{total: int, entries: list<array<string, mixed>>, versions: array<int, array<string, mixed>>}
	 *   `versions` holds the definitions the listed entries were submitted against.
	 * @throws NotFoundException
	 */
	public function listEntries(string $userId, int $id, int $limit, int $offset, ?EntryFilter $filter = null): array {
		$form = $this->get($userId, $id);
		$limit = max(1, min($limit, self::MAX_PAGE_SIZE));
		$offset = max(0, $offset);

		$filter ??= new EntryFilter();
		$found = $this->query->page($form->getId(), $filter, $limit, $offset);
		$result = [];
		$versionDefinitions = [];
		foreach ($found['entries'] as $entry) {
			$result[] = [
				'id' => $entry->getId(),
				'versionId' => $entry->getVersionId(),
				'userId' => $entry->getUserId(),
				'submittedAt' => $entry->getSubmittedAt(),
				'data' => JsonShape::map($entry->getDataArray()),
			];
			if (!isset($versionDefinitions[$entry->getVersionId()])) {
				$version = $this->versions->find($entry->getVersionId());
				$versionDefinitions[$entry->getVersionId()] = JsonShape::definition($version->getDefinitionArray());
			}
		}

		return [
			// How many match the filter. Without one it is every response.
			'total' => $found['total'],
			// How many the form has in all, for "12 of 340".
			'grandTotal' => $filter->isActive() ? $this->entries->countByForm($form->getId()) : $found['total'],
			// True when a text or answer search stopped early, so `total` is a lower bound.
			'truncated' => $found['truncated'],
			'entries' => $result,
			'versions' => $versionDefinitions,
		];
	}

	/**
	 * What the matching responses add up to (docs/SCHEMA.md section 17.2).
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 */
	public function summarizeEntries(string $userId, int $id, EntryFilter $filter): array {
		$form = $this->get($userId, $id);
		$summary = new EntrySummary();
		/** @var array<int, array{order: list<string>, fields: array<string, array<string, mixed>>}> $versions */
		$versions = [];
		$truncated = $this->query->each($form->getId(), $filter, function (Entry $entry) use ($summary, &$versions): void {
			$versionId = $entry->getVersionId();
			if (!isset($versions[$versionId])) {
				$definition = $this->versions->find($versionId)->getDefinitionArray();
				$versions[$versionId] = ['order' => CsvExporter::layoutOrder($definition), 'fields' => self::fieldsOf($definition)];
			}
			$summary->add($versionId, $versions[$versionId]['order'], $versions[$versionId]['fields'], $entry->getDataArray(), $entry->getSubmittedAt());
		});
		return $summary->result() + ['truncated' => $truncated];
	}

	/**
	 * @throws NotFoundException
	 */
	public function deleteEntry(string $userId, int $id, int $entryId): void {
		$form = $this->get($userId, $id);
		$this->removeEntry($form, $this->entries->findInForm($form->getId(), $entryId), $userId);
	}

	/**
	 * Delete several responses (docs/SCHEMA.md section 17.1): the ones with these ids, or every response a
	 * filter matches. A filter needs `$expected`, the number the person confirmed, and nothing is deleted
	 * if it no longer matches that many.
	 *
	 * @param list<int>|null $ids
	 * @return int how many were deleted
	 * @throws NotFoundException
	 * @throws ValidationException
	 * @throws \OCA\Webforms\Exception\CountChangedException
	 */
	public function deleteEntries(string $userId, int $id, ?array $ids, EntryFilter $filter, int $expected): int {
		$form = $this->get($userId, $id);
		if ($ids !== null) {
			if (count($ids) > self::MAX_BULK_IDS) {
				throw new ValidationException([['code' => 'too_many_selected', 'path' => 'ids']]);
			}
			$entries = $this->query->byIds($form->getId(), $ids);
		} else {
			$found = $this->query->all($form->getId(), $filter, self::MAX_BULK_MATCHES);
			if ($found['truncated']) {
				// Too many to delete in one go: narrow the filter, or let retention do it.
				throw new ValidationException([['code' => 'too_many_matches', 'path' => 'filter']]);
			}
			if (count($found['entries']) !== $expected) {
				throw new \OCA\Webforms\Exception\CountChangedException(count($found['entries']));
			}
			$entries = $found['entries'];
		}

		foreach ($entries as $entry) {
			$this->removeEntry($form, $entry, $userId);
		}
		return count($entries);
	}

	/**
	 * Everything that goes with one response: its uploaded files, its delivery log, and the response itself,
	 * then the event for Flow.
	 */
	private function removeEntry(Form $form, Entry $entry, string $deletedBy): void {
		$definition = $this->versions->find($entry->getVersionId())->getDefinitionArray();
		$this->uploads->deleteFilesOf($form->getOwnerId(), self::fieldsOf($definition), $entry->getDataArray());
		// Its deliveries go with it, so the log never points at a response that no longer exists.
		$this->actionLog->deleteByEntry($entry->getId());
		$this->entries->delete($entry);
		$this->events->publish(static fn (): EntryDeletedEvent => new EntryDeletedEvent(EventPayload::entryDeleted(
			$form->getId(),
			$form->getTitle(),
			$form->getOwnerId(),
			$entry->getVersionId(),
			$entry->getId(),
			$deletedBy,
		)));
	}

	/**
	 * One file of a response, for the owner to download.
	 *
	 * @return array{name: string, size: int, mime: string, stream: resource}
	 * @throws NotFoundException
	 */
	public function openEntryFile(string $userId, int $id, int $entryId, string $fieldId, int $index): array {
		$form = $this->get($userId, $id);
		$entry = $this->entries->findInForm($form->getId(), $entryId);
		$fields = self::fieldsOf($this->versions->find($entry->getVersionId())->getDefinitionArray());
		$data = $entry->getDataArray();
		$files = UploadService::storedFiles(isset($fields[$fieldId]) ? [$fieldId => $fields[$fieldId]] : [], isset($data[$fieldId]) ? [$fieldId => $data[$fieldId]] : []);
		if (!isset($files[$index])) {
			throw new NotFoundException();
		}
		$opened = $this->uploads->open($form->getOwnerId(), $files[$index]);
		if ($opened === null) {
			throw new NotFoundException();
		}
		return $opened;
	}

	/**
	 * Upload fields whose destination the owner may not use. The folder is checked against the owner's
	 * own permissions, so a form cannot send files anywhere its owner could not put them.
	 *
	 * @param array<string, mixed> $definition
	 * @return list<array{code: string, path: string}>
	 */
	private function uploadPermissionErrors(Form $form, array $definition): array {
		$title = is_string($definition['title'] ?? null) ? $definition['title'] : $form->getTitle();
		$errors = [];
		foreach (self::fieldsOf($definition) as $fieldId => $field) {
			if (($field['type'] ?? null) !== FieldType::FILE || UploadPolicy::storage($field) !== UploadPolicy::STORAGE_FILES) {
				continue;
			}
			try {
				$this->uploads->requireDestination($form->getOwnerId(), $title, $field, (string)$fieldId);
			} catch (UploadRejected $e) {
				$errors[] = ['code' => $e->reason, 'path' => 'fields.' . $fieldId];
			}
		}
		return $errors;
	}

	/**
	 * What only Tables can tell: the table exists, the owner may add rows to it, and each mapped column
	 * can take its source. Checked at publish and again on every run (docs/SCHEMA.md section 15).
	 *
	 * @param array<string, mixed> $definition
	 * @return list<array<string, mixed>>
	 */
	private function tablesErrors(Form $form, array $definition): array {
		$fields = self::fieldsOf($definition);
		$errors = [];
		$writable = null;
		foreach (is_array($definition['actions'] ?? null) ? $definition['actions'] : [] as $index => $action) {
			if (!is_array($action) || ($action['type'] ?? null) !== 'tables' || !is_int($action['tableId'] ?? null) || !is_array($action['map'] ?? null)) {
				continue;
			}
			$path = 'actions[' . $index . ']';
			if (!$this->tables->isAvailable()) {
				$errors[] = ['code' => 'tables_unavailable', 'path' => $path];
				continue;
			}
			try {
				$writable ??= array_column($this->tables->writableTables($form->getOwnerId()), 'id');
				if (!in_array($action['tableId'], $writable, true)) {
					$errors[] = ['code' => 'tables_not_allowed', 'path' => $path . '.tableId'];
					continue;
				}
				$columns = [];
				foreach ($this->tables->columns($action['tableId'], $form->getOwnerId()) as $column) {
					$columns[(int)$column['id']] = $column;
				}
			} catch (TablesFailure $e) {
				$errors[] = ['code' => $e->reason, 'path' => $path . '.tableId'];
				continue;
			}
			foreach ($action['map'] as $columnId => $source) {
				$column = $columns[(int)$columnId] ?? null;
				if ($column === null) {
					$errors[] = ['code' => 'tables_column_missing', 'path' => $path . '.map.' . $columnId];
					continue;
				}
				$sourceType = is_string($source) && in_array($source, TablesValue::META, true) ? $source : (string)($fields[$source]['type'] ?? '');
				if (!TablesValue::compatible($sourceType, $column)) {
					$errors[] = ['code' => 'tables_type_mismatch', 'path' => $path . '.map.' . $columnId];
				}
			}
		}
		return $errors;
	}

	/** Removes the uploaded files of every response, and uploads still waiting, when a form is deleted. */
	private function deleteUploadedFiles(Form $form): void {
		$definitions = [];
		for ($offset = 0; ; $offset += 200) {
			$page = $this->entries->findByForm($form->getId(), 200, $offset);
			foreach ($page as $entry) {
				$definitions[$entry->getVersionId()] ??= self::fieldsOf($this->versions->find($entry->getVersionId())->getDefinitionArray());
				$this->uploads->deleteFilesOf($form->getOwnerId(), $definitions[$entry->getVersionId()], $entry->getDataArray());
			}
			if (count($page) < 200) {
				break;
			}
		}
		$this->uploads->discardPending($form->getId());
	}

	/**
	 * @param array<string, mixed> $definition
	 * @return array<string, array<string, mixed>>
	 */
	private static function fieldsOf(array $definition): array {
		$fields = $definition['fields'] ?? [];
		return is_array($fields) ? array_filter($fields, 'is_array') : [];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(Form $form, bool $withDraft = false): array {
		$latestId = $form->getLatestVersionId();
		$latest = $latestId === null ? null : ($this->versions->findByIds([$latestId])[$latestId] ?? null);
		$data = $this->describe($form, $latest, $this->entries->countByForm($form->getId()));
		if ($withDraft) {
			$data['draft'] = JsonShape::definition($form->getDraftDefinition());
			$data['hasUnpublishedChanges'] = $latest === null ? true : $this->differs($form, $latest);
			// Ids that cannot be used again, so the builder can say so before the server has to refuse.
			$data['retiredIds'] = $form->getRetiredIdList();
		}
		return $data;
	}

	/**
	 * A form as the owner sees it. `availability` says whether the published form takes responses by
	 * its own limit and dates and the server's cap (docs/SCHEMA.md section 3.1); it is null until the
	 * form has been published. A closed or archived form takes none whatever it says.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(Form $form, ?Version $latest, int $responses): array {
		$availability = null;
		if ($latest !== null) {
			$settings = $latest->getDefinitionArray()['settings'] ?? [];
			$availability = Availability::of(
				is_array($settings) ? $settings : [],
				$this->time->getTime(),
				$responses,
				$this->policy->settings()->maxResponsesPerForm(),
			);
		}
		return [
			'id' => $form->getId(),
			'title' => $form->getTitle(),
			'description' => $form->getDescription(),
			'status' => $form->getStatus(),
			'publicToken' => $form->getPublicToken(),
			'slug' => $form->getSlug(),
			'embed' => $form->isEmbeddable(),
			'embedOrigins' => $form->getEmbedOriginList(),
			'latestVersionId' => $form->getLatestVersionId(),
			'archivedAt' => $form->getArchivedAt(),
			'createdAt' => $form->getCreatedAt(),
			'updatedAt' => $form->getUpdatedAt(),
			'availability' => $availability,
		];
	}

	private function differs(Form $form, Version $latest): bool {
		$draft = $this->encode(JsonShape::definition($form->getDraftDefinition()));
		return $this->canonical($latest->getDefinition()) !== $this->canonical($draft);
	}

	/**
	 * @param array<mixed>|\stdClass $value
	 */
	private function encode(array|\stdClass $value): string {
		return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Re-encode so that formatting differences do not count as changes.
	 */
	private function canonical(string $json): string {
		return $this->encode(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
	}
}
