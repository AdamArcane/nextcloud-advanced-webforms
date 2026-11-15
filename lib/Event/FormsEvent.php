<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Event;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IWebhookCompatibleEvent;

/**
 * What every Advanced Webforms event shares: a payload built by EventPayload. Webhook compatible, so
 * an administrator can send it anywhere with the Webhook Listeners app, and a workflow engine entity
 * (see FormsEntity) offers it as a Flow trigger. The class name is the event name in both.
 * See docs/SCHEMA.md section 14.
 */
abstract class FormsEvent extends Event implements IWebhookCompatibleEvent {
	/**
	 * @param array<string, mixed> $payload built by EventPayload
	 */
	public function __construct(private readonly array $payload) {
		parent::__construct();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getPayload(): array {
		return $this->payload;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getWebhookSerializable(): array {
		return $this->payload;
	}
}
