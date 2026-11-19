<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Access;

use OCA\Webforms\Db\Form;
use OCA\Webforms\Db\FormMapper;
use OCA\Webforms\Db\FormShare;
use OCA\Webforms\Db\FormShareMapper;
use OCA\Webforms\Exception\ForbiddenException;
use OCA\Webforms\Exception\NotFoundException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Works out what a person may do with a form (docs/SCHEMA.md section 22.3). The one place that decides:
 * every call that touches a form asks for the lowest role it needs and gets the form or an exception.
 */
class FormAccess {
	/** @var array<string, list<string>> */
	private array $groupCache = [];

	public function __construct(
		private readonly FormMapper $forms,
		private readonly FormShareMapper $shares,
		private readonly IGroupManager $groups,
		private readonly IUserManager $users,
	) {
	}

	/**
	 * The person's role on the form, or null when they have none.
	 */
	public function role(string $userId, Form $form): ?Role {
		$roles = [];
		if ($form->getOwnerId() !== $userId) {
			foreach ($this->shares->findForTargets($userId, $this->groupIds($userId)) as $share) {
				if ($share->getFormId() === $form->getId()) {
					$roles[] = $share->getRole();
				}
			}
		}
		return Role::resolve($form->getOwnerId() === $userId, $roles);
	}

	/**
	 * The form, if the person has at least this role on it.
	 *
	 * @throws NotFoundException they have no role at all, so the form is not there for them
	 * @throws ForbiddenException they have a role, but a lower one
	 */
	public function require(string $userId, int $formId, Role $needs): Form {
		$form = $this->forms->find($formId);
		$role = $this->role($userId, $form);
		if ($role === null) {
			throw new NotFoundException();
		}
		if (!$role->atLeast($needs)) {
			throw new ForbiddenException();
		}
		return $form;
	}

	/**
	 * Every form the person can open, with their role on each, newest first.
	 *
	 * @return list<array{form: Form, role: Role, direct: bool}> `direct` is whether a share was made with
	 *         their own account, which is the only kind they can leave
	 */
	public function accessible(string $userId): array {
		$result = [];
		$owned = [];
		foreach ($this->forms->findByOwner($userId) as $form) {
			$owned[$form->getId()] = true;
			$result[$form->getId()] = ['form' => $form, 'role' => Role::OWNER, 'direct' => false];
		}
		$roles = [];
		$direct = [];
		foreach ($this->shares->findForTargets($userId, $this->groupIds($userId)) as $share) {
			$formId = $share->getFormId();
			if (isset($owned[$formId])) {
				continue;
			}
			$roles[$formId] = Role::highest($roles[$formId] ?? null, Role::forShare($share->getRole()));
			if ($share->getShareType() === FormShare::TYPE_USER) {
				$direct[$formId] = true;
			}
		}
		foreach ($this->forms->findByIds(array_keys($roles)) as $form) {
			$role = $roles[$form->getId()] ?? null;
			if ($role !== null) {
				$result[$form->getId()] = ['form' => $form, 'role' => $role, 'direct' => isset($direct[$form->getId()])];
			}
		}
		$list = array_values($result);
		usort($list, static fn (array $a, array $b): int => $b['form']->getUpdatedAt() <=> $a['form']->getUpdatedAt() ?: $b['form']->getId() <=> $a['form']->getId());
		return $list;
	}

	/** The display name of an account, or its id when it is gone. */
	public function displayName(string $userId): string {
		return $this->users->getDisplayName($userId) ?? $userId;
	}

	/**
	 * @return list<string>
	 */
	private function groupIds(string $userId): array {
		if (!isset($this->groupCache[$userId])) {
			$user = $this->users->get($userId);
			$this->groupCache[$userId] = $user === null ? [] : array_values(array_map('strval', $this->groups->getUserGroupIds($user)));
		}
		return $this->groupCache[$userId];
	}
}
