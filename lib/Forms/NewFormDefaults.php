<?php

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Webforms\Forms;

/**
 * What a person's own defaults put in the settings of a form they create (docs/SCHEMA.md section 16.1).
 * Only what differs from the built-in behaviour is written, so a form made with no defaults set has
 * empty settings, as before.
 */
final class NewFormDefaults {
	/**
	 * @param array<string, mixed> $preferences as returned by `UserPreferences::forUser()`
	 * @return array<string, mixed>
	 */
	public static function settings(array $preferences): array {
		$settings = [];
		$label = $preferences['newFormLabelPosition'] ?? 'above';
		if ($label !== 'above' && in_array($label, DefinitionValidator::LABEL_POSITIONS, true)) {
			$settings['labelPosition'] = $label;
		}
		if (($preferences['newFormHideTitle'] ?? false) === true) {
			$settings['hideTitle'] = true;
		}
		if (($preferences['newFormCaptcha'] ?? true) === false) {
			$settings['captcha'] = false;
		}
		$accent = $preferences['newFormAccentColor'] ?? '';
		if (is_string($accent) && preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1) {
			$settings['design'] = ['accentColor' => strtolower($accent)];
		}
		return $settings;
	}
}
