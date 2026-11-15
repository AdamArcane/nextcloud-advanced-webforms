<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Uploads;

/**
 * A file that was uploaded but is not yet part of a submission. It waits here until the
 * form is submitted, and is thrown away if that never happens.
 */
final class PendingUpload {
	public function __construct(
		public readonly string $token,
		public readonly int $formId,
		public readonly string $fieldId,
		public readonly ?string $userId,
		public readonly string $name,
		public readonly int $size,
		public readonly string $mime,
		public readonly int $createdAt,
	) {
	}
}
