<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Actions;

/**
 * The action could not be completed. `$retryable` says whether trying again
 * later could help (a busy server) or not (a rejected request, a missing secret).
 */
final class ActionFailed extends \RuntimeException {
	public function __construct(string $message, public readonly bool $retryable) {
		parent::__construct($message);
	}
}
