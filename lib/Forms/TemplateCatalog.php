<?php

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Webforms\Forms;

use OCA\Webforms\Exception\NotFoundException;

/**
 * The starter templates that ship with the app (docs/SCHEMA.md section 13.4). Each is a JSON file in
 * `Templates/` holding an id, a category, a name, a description and a definition. They are read from disk, so
 * they need no table. Their text is English: the browser translates it when a form is made from one.
 */
final class TemplateCatalog {
	public const ID_PREFIX = 'b:';

	private readonly string $dir;

	public function __construct(?string $dir = null) {
		$this->dir = $dir ?? __DIR__ . '/Templates';
	}

	/**
	 * @return list<array{id: string, category: string, name: string, description: string, fieldCount: int}>
	 */
	public function all(): array {
		$items = [];
		foreach ($this->files() as $file) {
			$data = $this->read($file);
			if ($data === null) {
				continue;
			}
			$fields = $data['definition']['fields'] ?? [];
			$items[] = [
				'id' => self::ID_PREFIX . $data['id'],
				'category' => $data['category'],
				'name' => $data['name'],
				'description' => $data['description'],
				'fieldCount' => is_array($fields) ? count($fields) : 0,
			];
		}
		return $items;
	}

	/**
	 * What the person sees: the starter templates the administrator has not hidden.
	 *
	 * @param list<array{id: string, category: string, name: string, description: string, fieldCount: int}> $items
	 * @param list<string> $hidden
	 * @return list<array{id: string, category: string, name: string, description: string, fieldCount: int}>
	 */
	public static function withoutHidden(array $items, array $hidden): array {
		return array_values(array_filter($items, static fn (array $item): bool => !in_array($item['id'], $hidden, true)));
	}

	/**
	 * @return array<string, mixed> the definition
	 * @throws NotFoundException
	 */
	public function definition(string $id): array {
		if (!str_starts_with($id, self::ID_PREFIX)) {
			throw new NotFoundException();
		}
		$slug = substr($id, strlen(self::ID_PREFIX));
		if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $slug) !== 1) {
			throw new NotFoundException();
		}
		$data = $this->read($this->dir . '/' . $slug . '.json');
		if ($data === null || $data['id'] !== $slug) {
			throw new NotFoundException();
		}
		return $data['definition'];
	}

	/**
	 * @return list<string>
	 */
	private function files(): array {
		$files = glob($this->dir . '/*.json');
		if ($files === false) {
			return [];
		}
		sort($files);
		return $files;
	}

	/**
	 * @return array{id: string, category: string, name: string, description: string, definition: array<string, mixed>}|null
	 */
	private function read(string $file): ?array {
		$raw = is_file($file) ? file_get_contents($file) : false;
		if ($raw === false) {
			return null;
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || !is_string($data['id'] ?? null) || !is_string($data['category'] ?? null)
			|| !is_string($data['name'] ?? null) || !is_string($data['description'] ?? null)
			|| !is_array($data['definition'] ?? null)) {
			return null;
		}
		return $data;
	}
}
