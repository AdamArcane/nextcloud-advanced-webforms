<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Actions\Ports;

interface MailPort {
	/**
	 * @throws \Throwable when the mail could not be handed to the mail system
	 */
	public function send(string $to, string $subject, string $body): void;
}
