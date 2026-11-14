<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Uploads\Adapter;

use OCA\AdvancedForms\Uploads\Ports\OwnerFilesPort;
use OCA\AdvancedForms\Uploads\UploadRejected;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotEnoughSpaceException;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;

/**
 * Works in the form owner's Files, as the owner. The owner's own view of their files is the only
 * thing it can reach: a folder they cannot write to, or a path outside their home, is refused by
 * Nextcloud itself, and the checks below only add the share rules.
 */
final class NextcloudOwnerFiles implements OwnerFilesPort {
	public function __construct(
		private readonly IRootFolder $root,
		private readonly IShareManager $shares,
		private readonly IUserManager $users,
	) {
	}

	public function canWrite(string $ownerId, string $folder): bool {
		$node = $this->nearestExisting($ownerId, $folder);
		return $node instanceof Folder && $node->isCreatable();
	}

	public function canShareByLink(string $ownerId, string $folder): bool {
		$user = $this->users->get($ownerId);
		if ($user === null || !$this->shares->shareApiAllowLinks($user)) {
			return false;
		}
		$node = $this->nearestExisting($ownerId, $folder);
		return $node instanceof Folder && $node->isShareable();
	}

	public function store(string $ownerId, string $folder, string $subfolder, string $name, $stream): int {
		$dir = $this->ensureFolder($this->root->getUserFolder($ownerId), $folder . '/' . $subfolder);
		try {
			$file = $dir->newFile($dir->getNonExistingName($name), $stream);
		} catch (NotEnoughSpaceException) {
			throw new UploadRejected('upload_owner_storage_full');
		}
		return $file->getId();
	}

	public function open(string $ownerId, int $fileId): ?array {
		$file = $this->file($ownerId, $fileId);
		if ($file === null) {
			return null;
		}
		$stream = $file->fopen('r');
		return is_resource($stream) ? ['name' => $file->getName(), 'size' => $file->getSize(), 'mime' => $file->getMimeType(), 'stream' => $stream] : null;
	}

	public function delete(string $ownerId, int $fileId): void {
		$this->file($ownerId, $fileId)?->delete();
	}

	private function file(string $ownerId, int $fileId): ?File {
		foreach ($this->root->getUserFolder($ownerId)->getById($fileId) as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}
		return null;
	}

	/** The deepest part of the path that exists already: that is where permissions are decided. */
	private function nearestExisting(string $ownerId, string $folder): Node {
		$node = $this->root->getUserFolder($ownerId);
		foreach (explode('/', $folder) as $part) {
			if (!$node instanceof Folder || !$node->nodeExists($part)) {
				break;
			}
			$node = $node->get($part);
		}
		return $node;
	}

	private function ensureFolder(Folder $base, string $path): Folder {
		$dir = $base;
		foreach (explode('/', $path) as $part) {
			if ($dir->nodeExists($part)) {
				$next = $dir->get($part);
				if (!$next instanceof Folder) {
					throw new UploadRejected('upload_destination_unavailable');
				}
				$dir = $next;
			} else {
				$dir = $dir->newFolder($part);
			}
		}
		return $dir;
	}
}
