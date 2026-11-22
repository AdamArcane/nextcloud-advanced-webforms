<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Access\Role;
use OCA\Webforms\Actions\ActionPlanner;
use OCA\Webforms\BackgroundJob\ProcessActionsNowJob;
use OCA\Webforms\Db\ActionLogMapper;
use OCA\Webforms\Db\Entry;
use OCA\Webforms\Db\EntryMapper;
use OCA\Webforms\Db\VersionMapper;
use OCA\Webforms\Event\EntryPromotedEvent;
use OCA\Webforms\Event\EventPayload;
use OCA\Webforms\Event\EventPublisher;
use OCA\Webforms\Exception\ForbiddenException;
use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Moving a response off a form's waiting list (docs/SCHEMA.md section 3.2). Promoting is the owner's
 * decision and is allowed past the form's limit.
 */
class WaitlistService {
	public function __construct(
		private readonly FormService $forms,
		private readonly EntryMapper $entries,
		private readonly VersionMapper $versions,
		private readonly ActionPlanner $planner,
		private readonly ActionLogMapper $actionLog,
		private readonly PolicyService $policy,
		private readonly IJobList $jobs,
		private readonly EventPublisher $events,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{id: int, waitlisted: bool}
	 * @throws NotFoundException
	 * @throws ForbiddenException an Editor or the owner may promote
	 * @throws ValidationException `not_waitlisted` when the response is not on the waiting list
	 */
	public function promote(string $userId, int $formId, int $entryId): array {
		$form = $this->forms->get($userId, $formId, Role::EDITOR);
		$entry = $this->entries->findInForm($form->getId(), $entryId);
		// One statement decides, so two promotions of the same response cannot both go through.
		if (!$this->entries->setWaitlisted($form->getId(), $entry->getId(), false)) {
			throw new ValidationException([['code' => 'not_waitlisted']]);
		}
		$entry->setWaitlisted(0);

		$this->events->publish(static fn (): EntryPromotedEvent => new EntryPromotedEvent(EventPayload::entryPromoted(
			$form->getId(),
			$form->getTitle(),
			$form->getOwnerId(),
			$entry->getVersionId(),
			$entry->getId(),
			$userId,
		)));
		$this->runActions($formId, $entry);

		return ['id' => $entry->getId(), 'waitlisted' => false];
	}

	/**
	 * Queue the actions made for a promotion. The response is already moved, so nothing here may fail it.
	 */
	private function runActions(int $formId, Entry $entry): void {
		try {
			$definition = $this->versions->find($entry->getVersionId())->getDefinitionArray();
			$definition = $this->policy->withoutDisabledActions($definition);
			$fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
			$plan = $this->planner->plan($definition, SubmissionService::forConditions($fields, $entry->getDataArray()), ActionPlanner::ON_PROMOTED);

			$types = [];
			foreach (is_array($definition['actions'] ?? null) ? $definition['actions'] : [] as $action) {
				if (is_array($action) && is_string($action['id'] ?? null) && is_string($action['type'] ?? null)) {
					$types[$action['id']] = $action['type'];
				}
			}
			foreach ($plan['queue'] as $actionId) {
				$this->actionLog->enqueue($formId, $entry->getId(), $actionId, $types[$actionId] ?? '', $this->time->getTime());
			}
			if ($plan['queue'] !== []) {
				$this->jobs->add(ProcessActionsNowJob::class);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Advanced Webforms could not run the actions for a promoted response', ['exception' => $e, 'app' => 'webforms']);
		}
	}
}
