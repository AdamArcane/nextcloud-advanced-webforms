<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\BackgroundJob\ProcessActionsNowJob;
use OCA\Webforms\Db\ActionLog;
use OCA\Webforms\Db\ActionLogMapper;
use OCA\Webforms\Db\EntryMapper;
use OCA\Webforms\Db\VersionMapper;
use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;

/**
 * What the owner sees of email and webhook deliveries, and manual retry
 * (docs/SCHEMA.md section 7.4).
 */
class ActionLogService {
	public const MAX_PAGE_SIZE = 100;
	private const STATUSES = [ActionLog::STATUS_QUEUED, ActionLog::STATUS_SUCCESS, ActionLog::STATUS_FAILED, ActionLog::STATUS_SKIPPED];

	public function __construct(
		private readonly FormService $forms,
		private readonly ActionLogMapper $log,
		private readonly EntryMapper $entries,
		private readonly VersionMapper $versions,
		private readonly IJobList $jobs,
		private readonly ITimeFactory $time,
	) {
	}

	/**
	 * @return array{total: int, rows: list<array<string, mixed>>}
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function list(string $userId, int $formId, ?string $status, ?int $entryId, int $limit, int $offset): array {
		$form = $this->forms->get($userId, $formId);
		if ($status !== null && !in_array($status, self::STATUSES, true)) {
			throw new ValidationException([['code' => 'status_invalid', 'path' => 'status']]);
		}
		$limit = max(1, min($limit, self::MAX_PAGE_SIZE));
		$offset = max(0, $offset);

		$rows = [];
		$definitions = [];
		foreach ($this->log->findByForm($form->getId(), $status, $entryId, $limit, $offset) as $row) {
			$rows[] = $this->present($row, $definitions);
		}
		return ['total' => $this->log->countByForm($form->getId(), $status, $entryId), 'rows' => $rows];
	}

	/**
	 * Try a failed delivery again from scratch. Only failed ones: a skipped one
	 * had nothing to do, and a queued one is already on its way.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function retry(string $userId, int $formId, int $logId): array {
		$form = $this->forms->get($userId, $formId);
		$row = $this->log->findInForm($form->getId(), $logId);
		if ($row->getStatus() !== ActionLog::STATUS_FAILED) {
			throw new ValidationException([['code' => 'not_failed', 'path' => 'status']]);
		}
		$now = $this->time->getTime();
		$row->setStatus(ActionLog::STATUS_QUEUED);
		$row->setAttempt(0);
		$row->setError(null);
		$row->setNextAttemptAt($now);
		$row->setUpdatedAt($now);
		$this->log->update($row);
		$this->jobs->add(ProcessActionsNowJob::class);

		$definitions = [];
		return $this->present($row, $definitions);
	}

	/**
	 * @param array<int, array<string, mixed>> $definitions version definitions loaded so far
	 * @return array<string, mixed>
	 */
	private function present(ActionLog $row, array &$definitions): array {
		return [
			'id' => $row->getId(),
			'entryId' => $row->getEntryId(),
			'actionId' => $row->getActionId(),
			'type' => $row->getType() ?? '',
			'target' => $this->target($row, $definitions),
			'status' => $row->getStatus(),
			'attempt' => $row->getAttempt(),
			'error' => $row->getError(),
			'createdAt' => $row->getCreatedAt(),
			'updatedAt' => $row->getUpdatedAt(),
			'nextAttemptAt' => $row->getNextAttemptAt(),
		];
	}

	/**
	 * A short, human description of where the delivery goes: the address, who the
	 * respondent's address came from, or the webhook's host. Never a full URL,
	 * which can carry a token in its path or query.
	 *
	 * @param array<int, array<string, mixed>> $definitions
	 */
	private function target(ActionLog $row, array &$definitions): ?string {
		try {
			$entry = $this->entries->findInForm($row->getFormId(), $row->getEntryId());
			$versionId = $entry->getVersionId();
			$definitions[$versionId] ??= $this->versions->find($versionId)->getDefinitionArray();
		} catch (\Throwable) {
			return null;
		}
		$fields = is_array($definitions[$versionId]['fields'] ?? null) ? $definitions[$versionId]['fields'] : [];
		foreach (is_array($definitions[$versionId]['actions'] ?? null) ? $definitions[$versionId]['actions'] : [] as $action) {
			if (!is_array($action) || ($action['id'] ?? null) !== $row->getActionId()) {
				continue;
			}
			if (($action['type'] ?? null) === 'webhook' && is_string($action['url'] ?? null)) {
				$host = parse_url($action['url'], PHP_URL_HOST);
				return is_string($host) ? $host : null;
			}
			if (($action['type'] ?? null) === 'files' && is_string($action['path'] ?? null)) {
				// The owner's own path, which only the owner sees here.
				return $action['path'];
			}
			if (($action['type'] ?? null) === 'email') {
				$to = $action['to'] ?? null;
				if (is_string($to)) {
					return $to;
				}
				// Not an address, so the screen shows these as words (see DeliveryRow).
				if (is_array($to) && ($to['owner'] ?? null) === true) {
					return '@owner';
				}
				if (is_array($to) && ($to['respondent'] ?? null) === true) {
					return '@respondent';
				}
				if (is_array($to) && is_string($to['field'] ?? null)) {
					$label = $fields[$to['field']]['label'] ?? null;
					return is_string($label) ? $label : null;
				}
			}
		}
		return null;
	}
}
