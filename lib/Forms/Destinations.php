<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

use OCA\Webforms\Rules\FieldType;

/**
 * The places in the owner's Files and Tables that a definition reaches into (docs/SCHEMA.md section 22.5).
 * They run with the owner's access, so only the owner may change them: an editor who could would be using
 * access they do not have.
 *
 * The set is: a file field kept in Files (its folder and who may upload), a picture taken from Files,
 * and every action of type files or tables as a whole. Fields kept in the app, pictures from a web address
 * and the other action types are not in it, so an editor may change those freely.
 */
final class Destinations {
	/**
	 * @param array<string, mixed> $definition
	 * @return array<string, mixed> a place in the definition to what is there, in a form that compares
	 */
	public static function of(array $definition): array {
		$set = [];
		$fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
		foreach ($fields as $id => $field) {
			if (!is_array($field)) {
				continue;
			}
			$type = $field['type'] ?? null;
			if ($type === FieldType::FILE && UploadPolicy::storage($field) === UploadPolicy::STORAGE_FILES) {
				$set['fields.' . $id] = ['folder' => $field['folder'] ?? null, 'audience' => UploadPolicy::audience($field)];
			} elseif ($type === FieldType::IMAGE && ImageSource::kind($field['src'] ?? null) === ImageSource::FILES) {
				$set['fields.' . $id] = ['src' => $field['src']];
			}
		}
		$actions = is_array($definition['actions'] ?? null) ? $definition['actions'] : [];
		foreach ($actions as $index => $action) {
			if (is_array($action) && in_array($action['type'] ?? null, ['files', 'tables'], true)) {
				$set['actions.' . (is_string($action['id'] ?? null) ? $action['id'] : $index)] = self::canonical($action);
			}
		}
		return $set;
	}

	/**
	 * The places that are not the same in both: added, removed or changed.
	 *
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return list<string>
	 */
	public static function changes(array $before, array $after): array {
		$old = self::of($before);
		$new = self::of($after);
		$paths = [];
		foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $path) {
			if (!array_key_exists($path, $old) || !array_key_exists($path, $new) || self::canonical($old[$path]) !== self::canonical($new[$path])) {
				$paths[] = (string)$path;
			}
		}
		sort($paths);
		return $paths;
	}

	private static function canonical(mixed $value): mixed {
		if (is_array($value)) {
			$value = array_map(self::canonical(...), $value);
			if (!array_is_list($value)) {
				ksort($value);
			}
		}
		return $value;
	}
}
