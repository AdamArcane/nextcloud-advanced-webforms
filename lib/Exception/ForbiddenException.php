<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Exception;

use RuntimeException;

/** The person can see the form but their role does not allow this (docs/SCHEMA.md section 22.3). */
class ForbiddenException extends RuntimeException {
}
