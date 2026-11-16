<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions\Ports;

/**
 * Why writing the response file did not work, as a short code for the delivery log
 * (docs/SCHEMA.md section 19), and whether trying again later may help.
 */
final class FilesFailure extends \RuntimeException {
	public function __construct(
		public readonly string $reason,
		public readonly bool $retryable = false,
		string $detail = '',
	) {
		parent::__construct($detail === '' ? $reason : $reason . ': ' . $detail);
	}
}
