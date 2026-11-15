<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions\Adapter;

use OCA\Webforms\Actions\Ports\MailPort;
use OCP\Mail\IMailer;

/**
 * Sends through Nextcloud's configured mail server.
 */
final class NextcloudMail implements MailPort {
	public function __construct(
		private readonly IMailer $mailer,
	) {
	}

	public function send(string $to, string $subject, string $body, ?string $html = null, ?string $replyTo = null): void {
		$message = $this->mailer->createMessage();
		$message->setTo([$to]);
		$message->setSubject($subject);
		$message->setPlainBody($body);
		if ($html !== null) {
			$message->setHtmlBody($html);
		}
		if ($replyTo !== null) {
			$message->setReplyTo([$replyTo]);
		}
		$failed = $this->mailer->send($message);
		if ($failed !== []) {
			throw new \RuntimeException('The mail server rejected the recipient.');
		}
	}
}
