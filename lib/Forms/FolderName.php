<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

use OCA\Webforms\Exception\ValidationException;

/**
 * What a folder may be called (docs/SCHEMA.md section 24). Pure, so it is tested without a database.
 */
final class FolderName {
	public const MAX_LENGTH = 100;

	/**
	 * The name as it is kept: trimmed, with runs of white space made one space.
	 *
	 * @param list<string> $others the names of the person's other folders
	 * @throws ValidationException empty, too long, or one of `$others` apart from capital letters
	 */
	public static function resolve(string $given, array $others): string {
		$name = trim((string)preg_replace('/\s+/u', ' ', $given));
		if ($name === '' || mb_strlen($name) > self::MAX_LENGTH) {
			throw new ValidationException([['code' => 'folder_name_invalid', 'path' => 'name']]);
		}
		$wanted = mb_strtolower($name);
		foreach ($others as $other) {
			if (mb_strtolower($other) === $wanted) {
				throw new ValidationException([['code' => 'folder_name_taken', 'path' => 'name']]);
			}
		}
		return $name;
	}
}
