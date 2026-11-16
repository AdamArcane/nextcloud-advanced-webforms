<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Settings;

use OCA\Webforms\Forms\ResponseStatuses;
use OCA\Webforms\Security\Captcha\ConfigStore;

/**
 * Where the administrator's list of response statuses is kept (docs/SCHEMA.md section 20.2).
 */
class StatusSettings {
	private const KEY = 'response_statuses';

	public function __construct(
		private readonly ConfigStore $config,
	) {
	}

	/**
	 * @return list<array{id: string, label: string, color: string}>
	 */
	public function all(): array {
		return ResponseStatuses::parse($this->config->get(self::KEY));
	}

	public function has(string $id): bool {
		return in_array($id, array_column($this->all(), 'id'), true);
	}

	/**
	 * @return array<string, string> status id to label
	 */
	public function labels(): array {
		return array_column($this->all(), 'label', 'id');
	}

	/**
	 * @param list<array{id: string, label: string, color: string}> $statuses already validated
	 */
	public function save(array $statuses): void {
		// An empty list is stored as "[]", not as nothing, so it does not read as the defaults.
		$this->config->set(self::KEY, json_encode($statuses, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
	}
}
