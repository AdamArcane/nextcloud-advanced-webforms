<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

use OCA\Webforms\Exception\ValidationException;

/**
 * The line of text kept with a saved template, shown under its name when people choose one
 * (docs/SCHEMA.md section 13.4). Pure, so it is tested without a database.
 */
final class TemplateDescription {
	public const MAX_LENGTH = 500;
	/** What is taken from the form's own description when none was given. */
	public const DEFAULT_LENGTH = 160;

	/**
	 * Left out (null), the description is the form's own, cut to one line, so a template is not saved blank by
	 * accident. Given empty, it is empty: the person chose to have none.
	 *
	 * @param array<string, mixed> $definition the form the template is made from
	 * @throws ValidationException longer than MAX_LENGTH
	 */
	public static function resolve(?string $given, array $definition): string {
		if ($given === null) {
			$own = $definition['description'] ?? '';
			$own = is_string($own) ? trim((string)preg_replace('/\s+/u', ' ', $own)) : '';
			return mb_strimwidth($own, 0, self::DEFAULT_LENGTH, '...');
		}
		$given = trim($given);
		if (mb_strlen($given) > self::MAX_LENGTH) {
			throw new ValidationException([['code' => 'description_invalid', 'path' => 'description']]);
		}
		return $given;
	}
}
