<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Actions;

/**
 * The action had nothing to do for this entry (no recipient, rate limited).
 * Not a failure, and not retried.
 */
final class ActionSkipped extends \RuntimeException {
}
