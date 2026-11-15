<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Event;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Sends the app's events. A listener that throws must never fail a submission or a publish, so
 * every error is logged and swallowed.
 */
class EventPublisher {
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param Event|\Closure(): Event $event an event, or a closure that builds it (so building is protected too)
	 */
	public function publish(Event|\Closure $event): void {
		try {
			$this->dispatcher->dispatchTyped($event instanceof \Closure ? $event() : $event);
		} catch (\Throwable $e) {
			$this->logger->error('Advanced Webforms event listener failed', ['exception' => $e, 'app' => 'webforms']);
		}
	}
}
