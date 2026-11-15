<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Exception;

use RuntimeException;

/**
 * A bulk action was confirmed for a number of responses, but the filter now matches a different number
 * (more arrived, or some were deleted). Nothing was changed.
 */
class CountChangedException extends RuntimeException {
	public function __construct(public readonly int $current) {
		parent::__construct('The number of matching responses changed');
	}
}
