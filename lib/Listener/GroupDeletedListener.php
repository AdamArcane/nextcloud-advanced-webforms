<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Listener;

use OCA\Webforms\Db\FormShare;
use OCA\Webforms\Db\FormShareMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * When a group is deleted, the forms shared with it are no longer shared with anyone through it
 * (docs/SCHEMA.md section 22.7). Forms and responses stay.
 *
 * @template-implements IEventListener<GroupDeletedEvent>
 */
class GroupDeletedListener implements IEventListener {
	public function __construct(
		private readonly FormShareMapper $shares,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof GroupDeletedEvent) {
			return;
		}
		try {
			$this->shares->deleteByTarget(FormShare::TYPE_GROUP, $event->getGroup()->getGID());
		} catch (\Throwable $e) {
			// Deleting a group must not fail because of this app.
			$this->logger->error('Advanced Webforms could not remove the shares of a deleted group', ['exception' => $e, 'app' => 'webforms']);
		}
	}
}
