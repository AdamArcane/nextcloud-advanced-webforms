<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

/**
 * The statuses a response can be given (docs/SCHEMA.md section 20.2). One list for the whole server,
 * kept by the administrator. Pure, so it is unit tested: reading the stored list, and checking a list
 * before it is saved.
 */
final class ResponseStatuses {
	public const MAX = 20;
	public const MAX_LABEL = 40;
	public const ID_PATTERN = '/^[a-z][a-z0-9_]{2,31}$/D';
	/** In a filter: responses without a status. Never a status id, so it cannot be created. */
	public const NONE = 'none';
	public const COLORS = ['gray', 'red', 'orange', 'yellow', 'green', 'teal', 'blue', 'purple'];

	/**
	 * What a server starts with. Ordinary statuses: they can be renamed, reordered or deleted.
	 *
	 * @return list<array{id: string, label: string, color: string}>
	 */
	public static function defaults(): array {
		return [
			['id' => 'in_progress', 'label' => 'In progress', 'color' => 'blue'],
			['id' => 'done', 'label' => 'Done', 'color' => 'green'],
			['id' => 'spam', 'label' => 'Spam', 'color' => 'gray'],
		];
	}

	/**
	 * The stored list. Never configured means the defaults. A damaged list reads as empty, and a
	 * damaged entry is left out, so responses simply show no status until it is repaired.
	 *
	 * @return list<array{id: string, label: string, color: string}>
	 */
	public static function parse(string $stored): array {
		if ($stored === '') {
			return self::defaults();
		}
		$decoded = json_decode($stored, true);
		if (!is_array($decoded)) {
			return [];
		}
		$list = [];
		foreach ($decoded as $item) {
			$one = self::one($item);
			if ($one !== null && !isset($list[$one['id']]) && count($list) < self::MAX) {
				$list[$one['id']] = $one;
			}
		}
		return array_values($list);
	}

	/**
	 * A list the administrator wants to save, cleaned up.
	 *
	 * @param array<mixed> $input
	 * @return list<array{id: string, label: string, color: string}>
	 * @throws \InvalidArgumentException with a machine-readable reason
	 */
	public static function validate(array $input): array {
		if (count($input) > self::MAX) {
			throw new \InvalidArgumentException('too_many_statuses');
		}
		$list = [];
		foreach ($input as $item) {
			if (!is_array($item)) {
				throw new \InvalidArgumentException('status_invalid');
			}
			$id = $item['id'] ?? null;
			$label = is_string($item['label'] ?? null) ? trim($item['label']) : '';
			$color = $item['color'] ?? null;
			if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1 || $id === self::NONE) {
				throw new \InvalidArgumentException('status_id_invalid');
			}
			if (isset($list[$id])) {
				throw new \InvalidArgumentException('status_id_duplicate');
			}
			if ($label === '' || mb_strlen($label) > self::MAX_LABEL || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
				throw new \InvalidArgumentException('status_label_invalid');
			}
			if (!is_string($color) || !in_array($color, self::COLORS, true)) {
				throw new \InvalidArgumentException('status_color_invalid');
			}
			$list[$id] = ['id' => $id, 'label' => $label, 'color' => $color];
		}
		return array_values($list);
	}

	/**
	 * @return array{id: string, label: string, color: string}|null
	 */
	private static function one(mixed $item): ?array {
		try {
			return is_array($item) ? (self::validate([$item])[0] ?? null) : null;
		} catch (\InvalidArgumentException) {
			return null;
		}
	}
}
