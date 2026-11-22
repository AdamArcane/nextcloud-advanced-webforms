<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Event;

/**
 * The form owner moved a response off the waiting list (docs/SCHEMA.md section 3.2). Carries no answers.
 */
final class EntryPromotedEvent extends FormsEvent {
}
