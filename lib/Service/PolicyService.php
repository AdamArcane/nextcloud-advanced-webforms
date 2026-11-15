<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Settings\PolicyCheck;
use OCA\Webforms\Settings\PolicySettings;
use OCP\IGroupManager;

/**
 * Applies the administrator's policy (docs/SCHEMA.md section 16) where forms are created, published,
 * answered and run. The rules themselves are in PolicySettings and PolicyCheck.
 */
class PolicyService {
	public function __construct(
		private readonly PolicySettings $settings,
		private readonly IGroupManager $groups,
	) {
	}

	public function settings(): PolicySettings {
		return $this->settings;
	}

	/**
	 * Whether this account may create (or import) a form. Everyone may unless creator groups are set,
	 * and then only their members and administrators. Forms that already exist are not affected.
	 */
	public function canCreate(string $userId): bool {
		$groups = $this->settings->creatorGroups();
		if ($groups === [] || $this->groups->isAdmin($userId)) {
			return true;
		}
		foreach ($groups as $group) {
			if ($this->groups->isInGroup($userId, $group)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What policy says is wrong with this definition's actions.
	 *
	 * @param array<string, mixed> $definition
	 * @return list<array{code: string, path: string}>
	 */
	public function actionErrors(array $definition): array {
		return PolicyCheck::actionErrors($definition, $this->settings->allowedActionTypes(), $this->settings->maxActionsPerForm());
	}

	public function isActionTypeAllowed(string $type): bool {
		return in_array($type, $this->settings->allowedActionTypes(), true);
	}

	/**
	 * The definition with the actions the server no longer allows taken out.
	 *
	 * @param array<string, mixed> $definition
	 * @return array<string, mixed>
	 */
	public function withoutDisabledActions(array $definition): array {
		return PolicyCheck::withoutDisabledActions($definition, $this->settings->allowedActionTypes());
	}

	/**
	 * What the builder needs to know, for the signed-in user.
	 *
	 * @return array{canCreate: bool, allowedActionTypes: list<string>, maxActions: int}
	 */
	public function forUser(string $userId): array {
		return [
			'canCreate' => $this->canCreate($userId),
			'allowedActionTypes' => $this->settings->allowedActionTypes(),
			'maxActions' => $this->settings->maxActionsPerForm(),
		];
	}
}
