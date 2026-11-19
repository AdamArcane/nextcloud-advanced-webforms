<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Exception;

use RuntimeException;

/** The form's link has a password and the caller did not show that they know it. */
class PasswordRequiredException extends RuntimeException {
}
