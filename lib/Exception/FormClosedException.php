<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Exception;

use RuntimeException;

class FormClosedException extends RuntimeException {
	/** Closed by the owner, archived, or past its closing time. */
	public const CLOSED = 'closed';
	/** The opening time has not come yet. */
	public const NOT_OPEN = 'not_open';
	/** The form has as many responses as it may take. */
	public const FULL = 'full';

	public function __construct(
		private readonly string $closedMessage,
		private readonly string $reason = self::CLOSED,
	) {
		parent::__construct('Form is closed');
	}

	public function getReason(): string {
		return $this->reason;
	}

	public function getClosedMessage(): string {
		return $this->closedMessage;
	}
}
