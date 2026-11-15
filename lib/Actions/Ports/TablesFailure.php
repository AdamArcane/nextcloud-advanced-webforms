<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions\Ports;

/**
 * Something went wrong talking to Tables. `reason` is a short code the delivery log shows.
 */
final class TablesFailure extends \RuntimeException {
	public function __construct(
		public readonly string $reason,
		public readonly bool $retryable = false,
		string $detail = '',
	) {
		parent::__construct($detail === '' ? $reason : $reason . ': ' . $detail);
	}
}
