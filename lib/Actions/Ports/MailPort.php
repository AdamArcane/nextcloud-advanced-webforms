<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions\Ports;

interface MailPort {
	/**
	 * @param string|null $html the same message as HTML, sent beside the plain text when given
	 * @param string|null $replyTo the address replies go to, when it is not the sender
	 * @param string|null $fromName the name the mail is sent under; the address stays the server's own
	 * @throws \Throwable when the mail could not be handed to the mail system
	 */
	public function send(string $to, string $subject, string $body, ?string $html = null, ?string $replyTo = null, ?string $fromName = null): void;
}
