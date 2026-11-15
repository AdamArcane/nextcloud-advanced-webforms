<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Listener;

use OCA\Webforms\Db\EntryMapper;
use OCA\Webforms\Db\FormMapper;
use OCA\Webforms\Service\FormService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * When an account is deleted: its forms go too, with their responses, deliveries and
 * secrets (otherwise a public link keeps collecting answers for nobody), and responses the
 * person gave on other people's forms stay but no longer name them.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private readonly FormMapper $forms,
		private readonly FormService $service,
		private readonly EntryMapper $entries,
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
				$this->service->delete($userId, $form->getId());
			}
			$this->entries->anonymizeUser($userId);
		} catch (\Throwable $e) {
			// Deleting an account must not fail because of this app.
			$this->logger->error('Advanced Webforms could not clean up after a deleted user', ['exception' => $e, 'app' => 'webforms']);
		}
	}
}
