<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Actions\ActionContext;
use OCA\Webforms\Actions\ActionFailed;
use OCA\Webforms\Actions\ActionRunner;
use OCA\Webforms\Actions\ActionSkipped;
use OCA\Webforms\Actions\Backoff;
use OCA\Webforms\Db\ActionLog;
use OCA\Webforms\Db\ActionLogMapper;
use OCA\Webforms\Db\EntryMapper;
use OCA\Webforms\Db\FormMapper;
use OCA\Webforms\Db\VersionMapper;
use OCA\Webforms\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Works through the queued email and webhook deliveries (docs/SCHEMA.md 7.4).
 * A failure here only updates the log: it never touches the submission.
 */
class ActionProcessor {
	/** How long a picked-up row is reserved before another worker may take it. */
	private const LEASE_SECONDS = 300;
	private const ERROR_MAX_LENGTH = 500;

	public function __construct(
		private readonly ActionLogMapper $log,
		private readonly EntryMapper $entries,
		private readonly VersionMapper $versions,
		private readonly FormMapper $forms,
		private readonly ActionRunner $runner,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly PolicyService $policy,
	) {
	}

	/**
	 * @return int how many deliveries were handled
	 */
	public function processDue(int $limit = 25): int {
		$now = $this->time->getTime();
		$handled = 0;
		foreach ($this->log->findDue($now, $limit) as $row) {
			if (!$this->log->claim($row, $now + self::LEASE_SECONDS, $now)) {
				continue;
			}
			$this->handle($row);
			$handled++;
		}
		return $handled;
	}

	private function handle(ActionLog $row): void {
		$now = $this->time->getTime();
		$row->setAttempt($row->getAttempt() + 1);
		$row->setUpdatedAt($now);

		try {
			$entry = $this->entries->findInForm($row->getFormId(), $row->getEntryId());
			$definition = $this->versions->find($entry->getVersionId())->getDefinitionArray();
			$action = $this->findAction($definition, $row->getActionId());
			if ($action === null) {
				throw new ActionFailed('action_missing', false);
			}
			// An administrator may switch a type off after a response was queued. It is not retried.
			if (!$this->policy->isActionTypeAllowed((string)($action['type'] ?? ''))) {
				throw new ActionFailed('action_disabled', false);
			}

			$context = new ActionContext(
				formId: $row->getFormId(),
				versionId: $entry->getVersionId(),
				entryId: $entry->getId(),
				logId: $row->getId(),
				userId: $entry->getUserId(),
				submittedAt: $entry->getSubmittedAt(),
				fields: is_array($definition['fields'] ?? null) ? $definition['fields'] : [],
				data: $entry->getDataArray(),
				now: $now,
				recentEmails: $row->getType() === 'email' ? $this->log->countRecentEmails($row->getFormId(), $now - 3600) : 0,
				ownerId: $this->forms->find($row->getFormId())->getOwnerId(),
				pages: is_array($definition['pages'] ?? null) ? $definition['pages'] : [],
				mail: $this->policy->mail(),
			);
			$this->runner->run($action, $context);

			$row->setStatus(ActionLog::STATUS_SUCCESS);
			$row->setError(null);
			$row->setNextAttemptAt(null);
		} catch (ActionSkipped $e) {
			$row->setStatus(ActionLog::STATUS_SKIPPED);
			$row->setError($e->getMessage());
			$row->setNextAttemptAt(null);
		} catch (ActionFailed $e) {
			$this->recordFailure($row, $e->getMessage(), $e->retryable, $now);
		} catch (DoesNotExistException|NotFoundException) {
			// The entry or its version was deleted while the delivery waited.
			$this->recordFailure($row, 'entry_deleted', false, $now);
		} catch (\Throwable $e) {
			$this->logger->error('Advanced Webforms action failed unexpectedly', ['exception' => $e, 'app' => 'webforms']);
			$this->recordFailure($row, 'unexpected_error', true, $now);
		}

		$this->log->update($row);
	}

	private function recordFailure(ActionLog $row, string $reason, bool $retryable, int $now): void {
		$row->setError(mb_substr($reason, 0, self::ERROR_MAX_LENGTH));
		$delay = $retryable ? Backoff::delayAfter($row->getAttempt()) : null;
		if ($delay === null) {
			$row->setStatus(ActionLog::STATUS_FAILED);
			$row->setNextAttemptAt(null);
			return;
		}
		$row->setStatus(ActionLog::STATUS_QUEUED);
		$row->setNextAttemptAt($now + $delay);
	}

	/**
	 * @param array<string, mixed> $definition
	 * @return array<string, mixed>|null
	 */
	private function findAction(array $definition, string $actionId): ?array {
		foreach (is_array($definition['actions'] ?? null) ? $definition['actions'] : [] as $action) {
			if (is_array($action) && ($action['id'] ?? null) === $actionId) {
				return $action;
			}
		}
		return null;
	}
}
