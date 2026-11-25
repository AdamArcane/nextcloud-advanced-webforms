<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Access\FormAccess;
use OCA\Webforms\Access\Role;
use OCA\Webforms\Db\Folder;
use OCA\Webforms\Db\FolderFormMapper;
use OCA\Webforms\Db\FolderMapper;
use OCA\Webforms\Exception\ForbiddenException;
use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Forms\FolderName;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * A person's folders and what is in them (docs/SCHEMA.md section 24). Folders are private: every call works
 * on the caller's own, and putting a form in one changes nothing about who can open it.
 */
class FolderService {
	public const MAX_FOLDERS = 100;

	public function __construct(
		private readonly FolderMapper $folders,
		private readonly FolderFormMapper $contents,
		private readonly FormAccess $access,
		private readonly ITimeFactory $time,
	) {
	}

	/**
	 * @return list<array{id: int, name: string}> by name
	 */
	public function list(string $userId): array {
		$result = array_map(self::present(...), $this->folders->findByUser($userId));
		usort($result, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
		return $result;
	}

	/**
	 * @return array{id: int, name: string}
	 * @throws ValidationException
	 */
	public function create(string $userId, string $name): array {
		$existing = $this->folders->findByUser($userId);
		if (count($existing) >= self::MAX_FOLDERS) {
			throw new ValidationException([['code' => 'folder_limit', 'path' => '']]);
		}
		$folder = new Folder();
		$folder->setUserId($userId);
		$folder->setName(FolderName::resolve($name, self::names($existing)));
		$folder->setCreatedAt($this->time->getTime());
		return self::present($this->folders->insert($folder));
	}

	/**
	 * @return array{id: int, name: string}
	 * @throws NotFoundException
	 * @throws ValidationException
	 */
	public function rename(string $userId, int $folderId, string $name): array {
		$folder = $this->folders->findOwned($userId, $folderId);
		$others = array_values(array_filter(
			$this->folders->findByUser($userId),
			static fn (Folder $other): bool => $other->getId() !== $folder->getId(),
		));
		$folder->setName(FolderName::resolve($name, self::names($others)));
		return self::present($this->folders->update($folder));
	}

	/**
	 * Only the folder goes. The forms in it are then in none.
	 *
	 * @throws NotFoundException
	 */
	public function delete(string $userId, int $folderId): void {
		$folder = $this->folders->findOwned($userId, $folderId);
		$this->contents->deleteByFolder($folder->getId());
		$this->folders->delete($folder);
	}

	/**
	 * Put a form in one of the caller's folders, or with null take it out. Anyone who can open the form may,
	 * because it only changes their own list.
	 *
	 * @throws NotFoundException no such form for this person, or no such folder of theirs
	 * @throws ForbiddenException
	 */
	public function moveForm(string $userId, int $formId, ?int $folderId): void {
		$form = $this->access->require($userId, $formId, Role::REVIEWER);
		if ($folderId === null) {
			$this->contents->remove($form->getId(), $userId);
			return;
		}
		$folder = $this->folders->findOwned($userId, $folderId);
		$this->contents->set($form->getId(), $userId, $folder->getId());
	}

	/**
	 * @return array{id: int, name: string}
	 */
	private static function present(Folder $folder): array {
		return ['id' => $folder->getId(), 'name' => $folder->getName()];
	}

	/**
	 * @param list<Folder> $folders
	 * @return list<string>
	 */
	private static function names(array $folders): array {
		return array_map(static fn (Folder $folder): string => $folder->getName(), $folders);
	}
}
