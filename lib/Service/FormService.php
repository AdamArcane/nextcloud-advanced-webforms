<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Service;

use OCA\AdvancedForms\Db\ActionLogMapper;
use OCA\AdvancedForms\Db\EntryMapper;
use OCA\AdvancedForms\Db\Form;
use OCA\AdvancedForms\Db\FormMapper;
use OCA\AdvancedForms\Db\Version;
use OCA\AdvancedForms\Db\VersionMapper;
use OCA\AdvancedForms\Exception\NotFoundException;
use OCA\AdvancedForms\Exception\ValidationException;
use OCA\AdvancedForms\Forms\DefinitionValidator;
use OCA\AdvancedForms\Forms\JsonShape;
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

	public function __construct(
		private readonly FormMapper $forms,
		private readonly VersionMapper $versions,
		private readonly EntryMapper $entries,
		private readonly ActionLogMapper $actionLog,
		private readonly DefinitionValidator $validator,
		private readonly ITimeFactory $time,
		private readonly ISecureRandom $random,
	) {
	}

	/**
	 * @return list<Form>
	 */
	public function list(string $userId): array {
		return $this->forms->findByOwner($userId);
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
		$previous = array_map('strval', array_keys($form->getDraftDefinition()['fields'] ?? []));
		$removed = array_diff($previous, array_map('strval', array_keys($fields)));
		$retired = array_values(array_unique(array_merge($retired, $removed)));

		$form->setTitle(trim($title));
		$description = $definition['description'] ?? null;
		$form->setDescription(is_string($description) ? $description : null);
		$form->setDraft($encoded);
		$form->setRetiredIds($this->encode($retired));
		$form->setUpdatedAt($this->time->getTime());
		$form = $this->forms->update($form);

		return ['form' => $form, 'errors' => $this->validator->validate($definition)];
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
		$draft = $form->getDraftDefinition();

		$errors = $this->validator->validate($draft);
		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$encoded = $this->encode(JsonShape::definition($draft));
		$latest = $this->versions->findLatest($form->getId());
		if ($latest === null || $this->canonical($latest->getDefinition()) !== $this->canonical($encoded)) {
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
		return $this->forms->update($form);
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
		if (!in_array($status, [Form::STATUS_UNPUBLISHED, Form::STATUS_PUBLISHED, Form::STATUS_CLOSED], true)) {
			throw new ValidationException([['code' => 'status_invalid', 'path' => 'status']]);
		}
		if ($status !== Form::STATUS_UNPUBLISHED && $form->getLatestVersionId() === null) {
			throw new ValidationException([['code' => 'not_published_yet', 'path' => 'status']]);
		}
		$form->setStatus($status);
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
		$this->entries->deleteByForm($form->getId());
		$this->actionLog->deleteByForm($form->getId());
		$this->versions->deleteByForm($form->getId());
		$this->forms->delete($form);
	}

	/**
	 * @return array{total: int, entries: list<array<string, mixed>>, versions: array<int, array<string, mixed>>}
	 *   `versions` holds the definitions the listed entries were submitted against.
	 * @throws NotFoundException
	 */
	public function listEntries(string $userId, int $id, int $limit, int $offset): array {
		$form = $this->get($userId, $id);
		$limit = max(1, min($limit, self::MAX_PAGE_SIZE));
		$offset = max(0, $offset);

		$rows = $this->entries->findByForm($form->getId(), $limit, $offset);
		$result = [];
		$versionDefinitions = [];
		foreach ($rows as $entry) {
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
			'total' => $this->entries->countByForm($form->getId()),
			'entries' => $result,
			'versions' => $versionDefinitions,
		];
	}

	/**
	 * @throws NotFoundException
	 */
	public function deleteEntry(string $userId, int $id, int $entryId): void {
		$form = $this->get($userId, $id);
		$this->entries->delete($this->entries->findInForm($form->getId(), $entryId));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(Form $form, bool $withDraft = false): array {
		$data = [
			'id' => $form->getId(),
			'title' => $form->getTitle(),
			'description' => $form->getDescription(),
			'status' => $form->getStatus(),
			'publicToken' => $form->getPublicToken(),
			'latestVersionId' => $form->getLatestVersionId(),
			'createdAt' => $form->getCreatedAt(),
			'updatedAt' => $form->getUpdatedAt(),
		];
		if ($withDraft) {
			$data['draft'] = JsonShape::definition($form->getDraftDefinition());
		}
		return $data;
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
