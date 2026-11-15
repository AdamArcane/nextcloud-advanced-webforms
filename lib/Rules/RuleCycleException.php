<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Rules;

/**
 * Thrown when the rules of a definition depend on each other in a loop.
 */
final class RuleCycleException extends \InvalidArgumentException {
}
