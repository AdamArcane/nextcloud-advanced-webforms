<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions\Ports;

/**
 * Nextcloud Tables, seen as the Tables action needs it. Everything runs as the form owner, so Tables'
 * own permission checks decide what is allowed. A column is
 * `{id: int, title: string, type: string, subtype: string, mandatory: bool, options: list<{id: int, label: string}>}`
 * with Tables' own type and subtype names (for example `text` / `line`, `selection` / `multi`).
 */
interface TablesPort {
	/** Whether the Tables app is installed and enabled. */
	public function isAvailable(): bool;

	/**
	 * Tables the user may add rows to.
	 *
	 * @return list<array{id: int, title: string, emoji: string}>
	 */
	public function writableTables(string $userId): array;

	/**
	 * @return list<array<string, mixed>>
	 * @throws TablesFailure
	 */
	public function columns(int $tableId, string $userId): array;

	/**
	 * @param array<int, mixed> $values column id => value, already in the shape of the column
	 * @return int the new row's id
	 * @throws TablesFailure
	 */
	public function addRow(int $tableId, array $values, string $userId): int;

	/**
	 * Create a table with these columns, owned by the user.
	 *
	 * @param list<array{title: string, type: string, subtype: string, mandatory: bool, options: list<string>}> $columns
	 * @return array{tableId: int, columns: list<array<string, mixed>>} the columns as created, in order
	 * @throws TablesFailure
	 */
	public function createTable(string $title, array $columns, string $userId): array;
}
