<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Access\FormAccess;
use OCA\Webforms\Access\Role;
use OCA\Webforms\Db\FormShare;
use OCA\Webforms\Db\FormShareMapper;
use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Settings\PolicySettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IShare;

/**
 * Who a form is shared with (docs/SCHEMA.md sections 22.2 and 22.4). Managing it is the owner's; anyone with
 * a direct share may leave. People and groups are found with Nextcloud's own collaborator search, so the
 * server's sharing restrictions apply without any rule of ours.
 */
class CollaboratorService {
	public const SEARCH_LIMIT = 10;
	private const TYPES = ['user' => FormShare::TYPE_USER, 'group' => FormShare::TYPE_GROUP];

	public function __construct(
		private readonly FormAccess $access,
		private readonly FormShareMapper $shares,
		private readonly IUserManager $users,
		private readonly IGroupManager $groups,
		private readonly ISearch $search,
		private readonly ITimeFactory $time,
		private readonly PolicySettings $policy,
	) {
	}

	/**
	 * @return array{owner: array{id: string, displayName: string}, shares: list<array<string, mixed>>}
	 * @throws NotFoundException
	 * @throws \OCA\Webforms\Exception\ForbiddenException
	 */
	public function list(string $userId, int $formId): array {
		$form = $this->access->require($userId, $formId, Role::OWNER);
		return [
			'owner' => ['id' => $form->getOwnerId(), 'displayName' => $this->access->displayName($form->getOwnerId())],
			'shares' => array_map(fn (FormShare $share): array => $this->present($share), $this->shares->findByForm($form->getId())),
		];
	}

	/**
	 * Share the form with an account or a group, or change the role of a share that is there.
	 *
	 * @param string $type `user` or `group`
	 * @param string $role `editor` or `reviewer`
	 * @return array<string, mixed>
	 * @throws NotFoundException
	 * @throws \OCA\Webforms\Exception\ForbiddenException
	 * @throws ValidationException
	 */
	public function set(string $userId, int $formId, string $type, string $with, string $role): array {
		$form = $this->access->require($userId, $formId, Role::OWNER);
		$shareType = self::TYPES[$type] ?? null;
		$errors = [];
		$existing = $shareType === null ? null : $this->shares->findOne($form->getId(), (int)$shareType, $with);
		if (!$this->policy->sharingEnabled() && $existing === null) {
			// Off stops new shares; one that exists may still have its role changed (docs/SCHEMA.md section 22.8).
			throw new ValidationException([['code' => 'sharing_disabled', 'path' => '']]);
		}
		if ($shareType === null) {
			$errors[] = ['code' => 'share_type_invalid', 'path' => 'type'];
		}
		if (Role::forShare($role) === null) {
			$errors[] = ['code' => 'share_role_invalid', 'path' => 'role'];
		}
		if ($shareType !== null) {
			if ($shareType === FormShare::TYPE_USER && $with === $form->getOwnerId()) {
				$errors[] = ['code' => 'share_is_owner', 'path' => 'with'];
			} elseif (!$this->exists($shareType, $with)) {
				$errors[] = ['code' => 'share_target_invalid', 'path' => 'with'];
			}
		}
		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$share = $this->shares->findOne($form->getId(), (int)$shareType, $with);
		if ($share === null) {
			$share = new FormShare();
			$share->setFormId($form->getId());
			$share->setShareType((int)$shareType);
			$share->setShareWith($with);
			$share->setRole($role);
			$share->setCreatedBy($userId);
			$share->setCreatedAt($this->time->getTime());
			$share = $this->shares->insert($share);
		} elseif ($share->getRole() !== $role) {
			$share->setRole($role);
			$share = $this->shares->update($share);
		}
		return $this->present($share);
	}

	/**
	 * @throws NotFoundException
	 * @throws \OCA\Webforms\Exception\ForbiddenException
	 */
	public function remove(string $userId, int $formId, int $shareId): void {
		$form = $this->access->require($userId, $formId, Role::OWNER);
		$this->shares->delete($this->shares->findInForm($form->getId(), $shareId));
	}

	/**
	 * A person gives up their own access. Only a share made with their account can be left: one that
	 * comes through a group is the group's to take away.
	 *
	 * @throws NotFoundException they have no access at all
	 * @throws ValidationException `cannot_leave`: they reach the form through a group, or own it
	 */
	public function leave(string $userId, int $formId): void {
		$form = $this->access->require($userId, $formId, Role::REVIEWER);
		$share = $form->getOwnerId() === $userId ? null : $this->shares->findOne($form->getId(), FormShare::TYPE_USER, $userId);
		if ($share === null) {
			throw new ValidationException([['code' => 'cannot_leave', 'path' => '']]);
		}
		$this->shares->delete($share);
	}

	/**
	 * Accounts and groups the owner could share with, by what they typed. Leaves out the owner, the
	 * person asking, and everyone the form is already shared with.
	 *
	 * @return list<array{type: string, with: string, label: string}>
	 * @throws NotFoundException
	 * @throws \OCA\Webforms\Exception\ForbiddenException
	 */
	public function find(string $userId, int $formId, string $query): array {
		$form = $this->access->require($userId, $formId, Role::OWNER);
		$query = trim($query);
		if ($query === '' || !$this->policy->sharingEnabled()) {
			return [];
		}
		$taken = [];
		foreach ($this->shares->findByForm($form->getId()) as $share) {
			$taken[$share->getShareType() . ':' . $share->getShareWith()] = true;
		}
		$taken[FormShare::TYPE_USER . ':' . $form->getOwnerId()] = true;
		$taken[FormShare::TYPE_USER . ':' . $userId] = true;

		[$found] = $this->search->search($query, [IShare::TYPE_USER, IShare::TYPE_GROUP], false, self::SEARCH_LIMIT, 0);
		$found = is_array($found) ? $found : [];
		$candidates = array_merge(
			$found['exact']['users'] ?? [],
			$found['exact']['groups'] ?? [],
			$found['users'] ?? [],
			$found['groups'] ?? [],
		);
		$result = [];
		foreach ($candidates as $candidate) {
			$shareType = $candidate['value']['shareType'] ?? null;
			$with = $candidate['value']['shareWith'] ?? null;
			if (!in_array($shareType, [IShare::TYPE_USER, IShare::TYPE_GROUP], true) || !is_string($with) || isset($taken[$shareType . ':' . $with])) {
				continue;
			}
			$taken[$shareType . ':' . $with] = true;
			$result[] = [
				'type' => $shareType === IShare::TYPE_USER ? 'user' : 'group',
				'with' => $with,
				'label' => is_string($candidate['label'] ?? null) ? $candidate['label'] : $with,
			];
		}
		return $result;
	}

	private function exists(int $shareType, string $with): bool {
		return $shareType === FormShare::TYPE_USER ? $this->users->userExists($with) : $this->groups->groupExists($with);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present(FormShare $share): array {
		$isUser = $share->getShareType() === FormShare::TYPE_USER;
		$name = $isUser
			? $this->access->displayName($share->getShareWith())
			: ($this->groups->get($share->getShareWith())?->getDisplayName() ?? $share->getShareWith());
		return [
			'id' => $share->getId(),
			'type' => $isUser ? 'user' : 'group',
			'with' => $share->getShareWith(),
			'displayName' => $name,
			'role' => $share->getRole(),
			'createdAt' => $share->getCreatedAt(),
		];
	}
}
