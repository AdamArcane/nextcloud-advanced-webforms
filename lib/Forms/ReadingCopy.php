<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

use OCA\Webforms\Rules\FieldType;

/**
 * What someone who may only read responses is told about a form (docs/SCHEMA.md section 22.1). The
 * responses calls hand out the definition a response was submitted against so the page can show answers
 * under their questions. A definition also holds the actions (recipient addresses, webhook URLs), upload
 * folders and the owner's own notes, none of which a Reviewer needs.
 *
 * It is a list of what to keep, not of what to drop: a key the definition gains later is left out until
 * someone decides it is safe to show.
 */
final class ReadingCopy {
	/** Top level keys that are kept. `settings` and `actions` are not. */
	public const KEPT_TOP = ['schemaVersion', 'title', 'description', 'fields', 'pages', 'rules'];

	/** Keys of a field that are kept. A file field's `audience`, `storage` and `folder` are not. */
	public const KEPT_FIELD = [
		'type', 'label', 'description', 'required', 'defaultValue', 'labelPosition', 'hideLabel', 'placeholder',
		'minLength', 'maxLength', 'rows', 'min', 'max', 'step', 'prefix', 'suffix', 'minLabel', 'maxLabel',
		'text', 'options', 'columns', 'minSelected', 'maxSelected', 'maxFiles', 'maxSizeMb', 'accept',
		'level', 'align', 'style', 'src', 'alt', 'width',
	];

	/**
	 * @param array<string, mixed> $definition
	 * @return array<string, mixed>
	 */
	public static function of(array $definition): array {
		$copy = [];
		foreach (self::KEPT_TOP as $key) {
			if (array_key_exists($key, $definition)) {
				$copy[$key] = $definition[$key];
			}
		}
		if (is_array($copy['fields'] ?? null)) {
			foreach ($copy['fields'] as $id => $field) {
				$copy['fields'][$id] = is_array($field) ? self::field($field) : $field;
			}
		}
		return $copy;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>
	 */
	private static function field(array $field): array {
		$kept = array_intersect_key($field, array_flip(self::KEPT_FIELD));
		if (($field['type'] ?? null) === FieldType::HIDDEN) {
			// A hidden field's description is the owner's own note about it (section 4.7).
			unset($kept['description']);
		}
		if (($field['type'] ?? null) === FieldType::IMAGE && ImageSource::kind($field['src'] ?? null) === ImageSource::FILES) {
			// Where a picture sits in the owner's Files is the owner's business (section 4.9).
			$kept['src'] = ImageSource::FILES_MARKER;
		}
		return $kept;
	}
}
