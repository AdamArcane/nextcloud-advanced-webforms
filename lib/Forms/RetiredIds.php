<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Forms;

/**
 * Which field ids a form may no longer reuse (docs/SCHEMA.md section 4.1).
 *
 * An id is only worth reserving once responses could exist for it, that is once
 * it appeared in a published version. An id that only ever lived in a draft can
 * be changed, removed and typed again freely, which is what makes it practical
 * to rename a field while building.
 */
final class RetiredIds {
	/**
	 * @param list<string> $retired ids already reserved
	 * @param list<string> $previousDraftIds field ids in the draft before this save
	 * @param list<string> $newDraftIds field ids in the draft being saved
	 * @param list<string> $publishedIds field ids that appear in any published version
	 * @return list<string> the reserved ids after this save
	 */
	public static function after(array $retired, array $previousDraftIds, array $newDraftIds, array $publishedIds): array {
		$removed = array_diff(
			array_map('strval', $previousDraftIds),
			array_map('strval', $newDraftIds),
		);
		$published = array_flip(array_map('strval', $publishedIds));
		$newlyRetired = array_filter($removed, static fn (string $id): bool => isset($published[$id]));
		return array_values(array_unique(array_merge($retired, $newlyRetired)));
	}
}
