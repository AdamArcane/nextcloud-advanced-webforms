<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Listener;

use OCA\Webforms\WorkflowEngine\FormsEntity;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\WorkflowEngine\Events\RegisterEntitiesEvent;

/**
 * Tells the workflow engine about the Webforms trigger when it builds its list of entities.
 *
 * @template-implements IEventListener<Event>
 */
class RegisterEntitiesListener implements IEventListener {
	public function __construct(private readonly FormsEntity $entity) {
	}

	public function handle(Event $event): void {
		if ($event instanceof RegisterEntitiesEvent) {
			$event->registerEntity($this->entity);
		}
	}
}
