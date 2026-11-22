<?php

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Webforms\Import\CoreForms;

/**
 * What the converter made from one core Forms form (docs/SCHEMA.md section 13.3).
 *
 * `skipped` is the report: everything that could not come across, so the owner is never left to
 * discover a missing question or answer by themselves.
 */
final class ConversionResult {
	/**
	 * @param array<string, mixed> $definition a draft definition (docs/SCHEMA.md section 3)
	 * @param list<array{submittedAt: int, userId: ?string, data: array<string, mixed>}> $entries
	 * @param list<array{scope: string, ref: string, label: string, code: string, detail: string, count: int}> $skipped
	 * @param array<int, string> $fieldIds core question id => field id in the definition
	 */
	public function __construct(
		public readonly array $definition,
		public readonly array $entries,
		public readonly array $skipped,
		public readonly array $fieldIds,
	) {
	}
}
