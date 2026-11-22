<?php

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Webforms\Import\CoreForms;

use OCA\Webforms\Db\Entry;
use OCA\Webforms\Db\EntryMapper;
use OCA\Webforms\Db\Form;
use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Forms\FormPackage;
use OCA\Webforms\Forms\JsonShape;
use OCA\Webforms\Service\FormService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Brings forms (and optionally their responses) from the core Nextcloud Forms app into this one
 * (docs/SCHEMA.md section 13.3).
 *
 * The form always arrives as a new unpublished draft owned by the person named, through the same draft
 * save as any import, so nothing in core Forms is changed and nothing existing here is overwritten.
 * Responses need a version to belong to, so importing them publishes the form once and then closes it:
 * it takes no new responses until the owner reopens it.
 */
class CoreFormsImporter {
	public function __construct(
		private CoreFormsReader $reader,
		private CoreFormsConverter $converter,
		private FormService $forms,
		private EntryMapper $entries,
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param bool $dryRun convert and report only, create nothing
	 * @return array{
	 *     coreId: int,
	 *     title: string,
	 *     formId: ?int,
	 *     fields: int,
	 *     responsesFound: int,
	 *     responsesImported: int,
	 *     errors: list<array<string, mixed>>,
	 *     skipped: list<array{scope: string, ref: string, code: string, detail: string}>
	 * }
	 */
	public function import(int $coreFormId, string $ownerId, bool $withResponses, bool $dryRun): array {
		$loaded = $this->reader->load($coreFormId);
		if ($loaded === null) {
			throw new \InvalidArgumentException('There is no core Forms form with id ' . $coreFormId . '.');
		}
		$result = $this->converter->convert($loaded['form'], $loaded['questions'], $loaded['submissions']);

		$outcome = [
			'coreId' => $coreFormId,
			'title' => (string)$result->definition['title'],
			'formId' => null,
			'fields' => count((array)$result->definition['fields']),
			'responsesFound' => count($result->entries),
			'responsesImported' => 0,
			'errors' => [],
			'skipped' => $result->skipped,
		];
		if ($dryRun) {
			return $outcome;
		}

		$package = FormPackage::build($result->definition, FormPackage::SOURCE_DRAFT, $this->time->getTime());
		$created = $this->forms->importForm($ownerId, $package);
		$form = $created['form'];
		$outcome['formId'] = $form->getId();
		// Problems with the draft do not stop the import: they block publishing, which is where they are fixed.
		$outcome['errors'] = $created['errors'];

		if ($withResponses && $result->entries !== []) {
			$outcome = $this->importResponses($outcome, $form, $ownerId, $result);
		}
		return $outcome;
	}

	/**
	 * @param array<string, mixed> $outcome
	 * @return array<string, mixed>
	 */
	private function importResponses(array $outcome, Form $form, string $ownerId, ConversionResult $result): array {
		try {
			$published = $this->forms->publish($ownerId, $form->getId());
			$this->forms->setStatus($ownerId, $form->getId(), Form::STATUS_CLOSED);
		} catch (ValidationException $e) {
			// The responses need a published version. A draft that cannot be published keeps its form but
			// not its responses, and says why.
			$outcome['skipped'][] = [
				'scope' => 'form',
				'ref' => 'responses',
				'code' => 'responses_not_imported',
				'detail' => 'The responses were not imported because the form could not be published: '
					. implode(', ', array_map(static fn (array $error): string => (string)($error['code'] ?? '?'), $e->getErrors()))
					. '. Fix the draft, then import again.',
			];
			return $outcome;
		}

		$versionId = $published->getLatestVersionId();
		if ($versionId === null) {
			return $outcome;
		}

		$imported = 0;
		$this->db->beginTransaction();
		try {
			foreach ($result->entries as $item) {
				$entry = new Entry();
				$entry->setFormId($form->getId());
				$entry->setVersionId($versionId);
				$entry->setUserId($item['userId']);
				$entry->setData(json_encode(JsonShape::map($item['data']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
				$entry->setSubmittedAt($item['submittedAt']);
				$this->entries->insert($entry);
				$imported++;
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		// Old responses are not news to the owner.
		$this->forms->markResponsesSeen($ownerId, $form->getId());

		$outcome['responsesImported'] = $imported;
		return $outcome;
	}
}
