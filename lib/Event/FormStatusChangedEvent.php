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
 * A form was unpublished, reopened or closed.
 *
 * Webhook compatible, so an administrator can send it to Flow, Windmill or any receiver with the
 * Webhook Listeners app. The class name is what is registered there. See docs/SCHEMA.md section 14.
 */
class FormStatusChangedEvent extends Event implements IWebhookCompatibleEvent {
	/**
	 * @param array<string, mixed> $payload built by EventPayload
	 */
	public function __construct(private readonly array $payload) {
		parent::__construct();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getWebhookSerializable(): array {
		return $this->payload;
	}
}
