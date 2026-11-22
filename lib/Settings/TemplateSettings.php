<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Settings;

use OCA\Webforms\Security\Captcha\ConfigStore;

/**
 * Which starter templates the administrator has hidden from everyone (docs/SCHEMA.md section 13.4). Nothing
 * hidden is the default, and a damaged value hides nothing, so a template never disappears by accident.
 */
class TemplateSettings {
	private const HIDDEN = 'templates_hidden';

	public function __construct(
		private readonly ConfigStore $config,
	) {
	}

	/**
	 * @return list<string>
	 */
	public function hidden(): array {
		$decoded = json_decode($this->config->get(self::HIDDEN), true);
		return is_array($decoded) ? self::clean($decoded, null) : [];
	}

	/**
	 * @param list<string> $ids already checked against the starter templates that exist
	 */
	public function saveHidden(array $ids): void {
		$this->config->set(self::HIDDEN, json_encode(array_values($ids), JSON_THROW_ON_ERROR));
	}

	/**
	 * Strings only, each once, and when a list of known ids is given only those.
	 *
	 * @param array<mixed> $ids
	 * @param list<string>|null $known
	 * @return list<string>
	 */
	public static function clean(array $ids, ?array $known): array {
		$out = [];
		foreach ($ids as $id) {
			if (is_string($id) && !in_array($id, $out, true) && ($known === null || in_array($id, $known, true))) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
