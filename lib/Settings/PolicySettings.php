<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Settings;

use OCA\Webforms\Forms\DefinitionValidator;
use OCA\Webforms\Security\Captcha\ConfigStore;

/**
 * The administrator's server-wide policy (docs/SCHEMA.md section 16): which action types are allowed,
 * how long responses are kept, limits per form, and who may create forms. Every value has a default
 * that keeps today's behaviour, and a number that is missing or damaged falls back to it. A damaged
 * list of allowed action types allows none, the safe side. Nothing here can lock an administrator out.
 */
class PolicySettings {
	public const MAX_RETENTION_DAYS = 3650;
	public const MAX_RESPONSES_LIMIT = 1000000;
	public const MAX_GROUPS = 100;

	private const ACTION_TYPES = 'policy_action_types';
	private const RETENTION_DAYS = 'policy_retention_days';
	private const MAX_RESPONSES = 'policy_max_responses';
	private const MAX_ACTIONS = 'policy_max_actions';
	private const CREATOR_GROUPS = 'policy_creator_groups';
	private const SHARING = 'policy_sharing';
	private const API = 'policy_api';

	public function __construct(
		private readonly ConfigStore $config,
	) {
	}

	/**
	 * Action types forms may use. Never configured means all of them.
	 *
	 * @return list<string>
	 */
	public function allowedActionTypes(): array {
		$stored = $this->config->get(self::ACTION_TYPES);
		if ($stored === '') {
			return DefinitionValidator::ACTION_TYPES;
		}
		return array_values(array_intersect(DefinitionValidator::ACTION_TYPES, self::list($stored)));
	}

	/** Responses older than this many days are deleted. 0 keeps them for ever. */
	public function retentionDays(): int {
		return self::number($this->config->get(self::RETENTION_DAYS), 0, self::MAX_RETENTION_DAYS, 0);
	}

	/** A form takes no more responses than this. 0 means no limit. */
	public function maxResponsesPerForm(): int {
		return self::number($this->config->get(self::MAX_RESPONSES), 0, self::MAX_RESPONSES_LIMIT, 0);
	}

	/** Actions per form. The structure allows at most DefinitionValidator::MAX_ACTIONS, the policy can only lower it. */
	public function maxActionsPerForm(): int {
		return self::number($this->config->get(self::MAX_ACTIONS), 1, DefinitionValidator::MAX_ACTIONS, DefinitionValidator::MAX_ACTIONS);
	}

	/**
	 * Group ids whose members may create forms. Empty means every user.
	 *
	 * @return list<string>
	 */
	public function creatorGroups(): array {
		return self::list($this->config->get(self::CREATOR_GROUPS));
	}

	/**
	 * Whether forms may be shared with other accounts and groups (docs/SCHEMA.md section 22.8). On unless
	 * switched off. Off stops new shares and the search for people; shares that exist keep working.
	 */
	public function sharingEnabled(): bool {
		return $this->config->get(self::SHARING) !== '0';
	}

	/**
	 * Whether other software may read forms and responses through the read-only API (docs/SCHEMA.md
	 * section 23). On unless switched off. Off, every route of it answers 404.
	 */
	public function apiEnabled(): bool {
		return $this->config->get(self::API) !== '0';
	}

	/**
	 * @param list<string> $actionTypes
	 * @param list<string> $creatorGroups
	 */
	public function save(array $actionTypes, int $retentionDays, int $maxResponses, int $maxActions, array $creatorGroups, bool $sharing = true, bool $api = true): void {
		$this->config->set(self::SHARING, $sharing ? '1' : '0');
		$this->config->set(self::API, $api ? '1' : '0');
		$types = array_values(array_intersect(DefinitionValidator::ACTION_TYPES, $actionTypes));
		// "All" is stored as nothing, so a type added in a later version is allowed unless switched off.
		$this->config->set(self::ACTION_TYPES, count($types) === count(DefinitionValidator::ACTION_TYPES) ? '' : json_encode($types, JSON_THROW_ON_ERROR));
		$this->config->set(self::RETENTION_DAYS, (string)max(0, min(self::MAX_RETENTION_DAYS, $retentionDays)));
		$this->config->set(self::MAX_RESPONSES, (string)max(0, min(self::MAX_RESPONSES_LIMIT, $maxResponses)));
		$this->config->set(self::MAX_ACTIONS, (string)max(1, min(DefinitionValidator::MAX_ACTIONS, $maxActions)));
		$groups = array_values(array_unique(array_filter(array_map('strval', $creatorGroups), static fn (string $id): bool => $id !== '' && mb_strlen($id) <= 64)));
		$this->config->set(self::CREATOR_GROUPS, json_encode(array_slice($groups, 0, self::MAX_GROUPS), JSON_THROW_ON_ERROR));
	}

	/**
	 * @return list<string>
	 */
	private static function list(string $stored): array {
		$decoded = json_decode($stored, true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_unique(array_filter($decoded, static fn (mixed $item): bool => is_string($item) && $item !== '')));
	}

	private static function number(string $stored, int $min, int $max, int $default): int {
		return preg_match('/^[0-9]{1,9}$/D', $stored) === 1 && (int)$stored >= $min && (int)$stored <= $max ? (int)$stored : $default;
	}
}
