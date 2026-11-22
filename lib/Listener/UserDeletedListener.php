<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Listener;

use OCA\Webforms\Db\EntryMapper;
use OCA\Webforms\Db\FormMapper;
use OCA\Webforms\Db\FormSeenMapper;
use OCA\Webforms\Db\FormTemplateMapper;
use OCA\Webforms\Db\FormShare;
use OCA\Webforms\Db\FormShareMapper;
use OCA\Webforms\Service\FormService;
use OCA\Webforms\Service\OwnershipService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * When an account is deleted: its forms pass to an editor who may create forms, or else go too, with their
 * responses, deliveries and secrets (otherwise a public link keeps collecting answers for nobody), and responses the
 * person gave on other people's forms stay but no longer name them.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private readonly FormMapper $forms,
		private readonly FormService $service,
		private readonly OwnershipService $ownership,
		private readonly EntryMapper $entries,
		private readonly FormShareMapper $shares,
		private readonly FormSeenMapper $seen,
		private readonly FormTemplateMapper $templates,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$userId = $event->getUser()->getUID();
		try {
			foreach ($this->forms->findByOwner($userId) as $form) {
				// A form with an editor who may create forms goes on without its owner (docs/SCHEMA.md section 22.7).
				if (!$this->ownership->passOnFromDeletedOwner($form)) {
					$this->service->delete($userId, $form->getId());
				}
			}
			// What was shared with them, and what they had seen, goes with the account (docs/SCHEMA.md section 22.7).
			$this->shares->deleteByTarget(FormShare::TYPE_USER, $userId);
			$this->seen->deleteByUser($userId);
			// Their saved templates are theirs alone, so they go with them.
			$this->templates->deleteByOwner($userId);
			$this->entries->anonymizeUser($userId);
		} catch (\Throwable $e) {
			// Deleting an account must not fail because of this app.
			$this->logger->error('Advanced Webforms could not clean up after a deleted user', ['exception' => $e, 'app' => 'webforms']);
		}
	}
}
