<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Actions;

/**
 * Everything an action needs to know about the entry it runs for.
 */
final class ActionContext {
	/**
	 * @param array<string, array<string, mixed>> $fields the definition's fields
	 * @param array<string, mixed> $data the entry's answers (visible fields only)
	 * @param int $recentEmails emails already sent or queued for this form in the last hour
	 */
	public function __construct(
		public readonly int $formId,
		public readonly int $versionId,
		public readonly int $entryId,
		public readonly int $logId,
		public readonly ?string $userId,
		public readonly int $submittedAt,
		public readonly array $fields,
		public readonly array $data,
		public readonly int $now,
		public readonly int $recentEmails = 0,
		/** The account that owns the form, for `to: { owner: true }`. */
		public readonly string $ownerId = '',
	) {
	}
}
