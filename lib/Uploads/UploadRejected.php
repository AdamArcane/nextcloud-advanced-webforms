<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Uploads;

/**
 * An upload that cannot be accepted. The code is shown to the respondent (see src/components/render/messages.ts).
 */
final class UploadRejected extends \RuntimeException {
	public function __construct(public readonly string $reason, public readonly ?string $fieldId = null) {
		parent::__construct($reason);
	}
}
