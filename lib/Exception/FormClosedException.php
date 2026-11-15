<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Exception;

use RuntimeException;

class FormClosedException extends RuntimeException {
	public function __construct(private readonly string $closedMessage) {
		parent::__construct('Form is closed');
	}

	public function getClosedMessage(): string {
		return $this->closedMessage;
	}
}
