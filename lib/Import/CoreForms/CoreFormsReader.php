<?php

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Webforms\Import\CoreForms;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads the forms of the core Nextcloud Forms app straight from its tables, on the same server
 * (docs/SCHEMA.md section 13.3). It only ever selects.
 *
 * It returns the plain arrays `CoreFormsConverter` takes. Core Forms does not promise its table layout
 * to other apps, so every column is read by name with a fallback and a missing one means "not set", never
 * an error: the importer reports what it could not read instead of failing halfway.
 */
class CoreFormsReader {
	private const FORMS = 'forms_v2_forms';
	private const QUESTIONS = 'forms_v2_questions';
	private const OPTIONS = 'forms_v2_options';
	private const SUBMISSIONS = 'forms_v2_submissions';
	private const ANSWERS = 'forms_v2_answers';

	private const CHUNK = 500;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Whether core Forms has ever been installed here.
	 */
	public function isAvailable(): bool {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from(self::FORMS)->setMaxResults(1);
			$qb->executeQuery()->closeCursor();
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @return list<array{id: int, title: string, ownerId: string}>
	 */
	public function listForms(?int $onlyId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::FORMS);
		if ($onlyId !== null) {
			$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($onlyId, IQueryBuilder::PARAM_INT)));
		}
		$forms = [];
		foreach ($this->rows($qb) as $row) {
			$forms[] = [
				'id' => (int)$row['id'],
				'title' => (string)($row['title'] ?? ''),
				'ownerId' => (string)($row['owner_id'] ?? ''),
			];
		}
		usort($forms, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
		return $forms;
	}

	/**
	 * @return array{form: array<string, mixed>, questions: list<array<string, mixed>>, submissions: list<array<string, mixed>>}|null
	 */
	public function load(int $formId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::FORMS)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		$formRow = $this->rows($qb)[0] ?? null;
		if ($formRow === null) {
			return null;
		}

		$form = [
			'title' => (string)($formRow['title'] ?? ''),
			'description' => (string)($formRow['description'] ?? ''),
			'submissionMessage' => $formRow['submission_message'] ?? null,
			'opens' => (int)($formRow['opens'] ?? 0),
			'expires' => (int)($formRow['expires'] ?? 0),
			'isAnonymous' => (bool)($formRow['is_anonymous'] ?? false),
			'submitMultiple' => array_key_exists('submit_multiple', $formRow) ? (bool)$formRow['submit_multiple'] : null,
			'confirmationEmailEnabled' => (bool)($formRow['confirmation_email_enabled'] ?? false),
		];

		return [
			'form' => $form,
			'questions' => $this->questions($formId),
			'submissions' => $this->submissions($formId),
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function questions(int $formId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::QUESTIONS)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		$rows = $this->rows($qb);

		// A question with order 0 is a deleted one (core Forms keeps it for its own history).
		$rows = array_values(array_filter($rows, static fn (array $r): bool => (int)($r['order'] ?? 1) !== 0));
		$ids = array_map(static fn (array $r): int => (int)$r['id'], $rows);

		$options = [];
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$oqb = $this->db->getQueryBuilder();
			$oqb->select('*')->from(self::OPTIONS)
				->where($oqb->expr()->in('question_id', $oqb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->rows($oqb) as $option) {
				$options[(int)$option['question_id']][] = [
					'id' => (int)$option['id'],
					'text' => (string)($option['text'] ?? ''),
					'order' => (int)($option['order'] ?? 0),
					'optionType' => (string)($option['option_type'] ?? 'choice'),
				];
			}
		}

		$questions = [];
		foreach ($rows as $row) {
			$extra = $row['extra_settings_json'] ?? $row['extra_settings'] ?? null;
			$decoded = is_string($extra) && $extra !== '' ? json_decode($extra, true) : null;
			$question = [
				'id' => (int)$row['id'],
				'order' => (int)($row['order'] ?? 0),
				'type' => (string)($row['type'] ?? ''),
				'isRequired' => (bool)($row['is_required'] ?? false),
				'text' => (string)($row['text'] ?? ''),
				'description' => (string)($row['description'] ?? ''),
				'extraSettings' => is_array($decoded) ? $decoded : [],
				'options' => $options[(int)$row['id']] ?? [],
			];
			if (array_key_exists('parent_question_id', $row)) {
				$question['parentQuestionId'] = $row['parent_question_id'] === null ? null : (int)$row['parent_question_id'];
			}
			$questions[] = $question;
		}
		return $questions;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function submissions(int $formId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::SUBMISSIONS)
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		$submissions = [];
		foreach ($this->rows($qb) as $row) {
			$submissions[(int)$row['id']] = [
				'id' => (int)$row['id'],
				'userId' => $row['user_id'] ?? null,
				'timestamp' => (int)($row['timestamp'] ?? 0),
				'answers' => [],
			];
		}
		ksort($submissions);

		foreach (array_chunk(array_keys($submissions), self::CHUNK) as $chunk) {
			$aqb = $this->db->getQueryBuilder();
			$aqb->select('*')->from(self::ANSWERS)
				->where($aqb->expr()->in('submission_id', $aqb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->rows($aqb) as $answer) {
				$submissions[(int)$answer['submission_id']]['answers'][] = [
					'id' => (int)$answer['id'],
					'questionId' => (int)$answer['question_id'],
					'text' => $answer['text'] ?? null,
					'fileId' => isset($answer['file_id']) ? (int)$answer['file_id'] : null,
				];
			}
		}

		foreach ($submissions as &$submission) {
			usort($submission['answers'], static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
		}
		unset($submission);
		return array_values($submissions);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function rows(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		try {
			return array_values($result->fetchAll());
		} finally {
			$result->closeCursor();
		}
	}
}
