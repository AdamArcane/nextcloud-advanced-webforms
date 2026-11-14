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
		if (!$this->plain($folder)) {
			return false;
		}
		$node = $this->nearestExisting($ownerId, $folder);
		return $node instanceof Folder && $node->isCreatable();
	}

	public function canShareByLink(string $ownerId, string $folder): bool {
		$user = $this->users->get($ownerId);
		if (!$this->plain($folder) || $user === null || !$this->shares->shareApiAllowLinks($user)) {
			return false;
		}
		$node = $this->nearestExisting($ownerId, $folder);
		if (!$node instanceof Folder) {
			return false;
		}
		$home = $this->root->getUserFolder($ownerId);
		$exists = $node->getPath() === $home->getPath() . '/' . $folder;
		// A folder that exists must be shareable itself. One that will be created is the owner's own, so
		// it is shareable wherever it goes, unless it would sit inside a share that cannot be re-shared.
		return $exists ? $node->isShareable() : ($node->getPath() === $home->getPath() || $node->isShareable());
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
		$file = $this->file($ownerId, $fileId);
		if ($file === null) {
			return;
		}
		$parent = $file->getParent();
		$file->delete();
		// The per-response folder this app made goes with its last file; any other folder stays.
		if (str_starts_with($parent->getName(), 'Response ') && $parent->getDirectoryListing() === []) {
			$parent->delete();
		}
	}

	/** A folder path without `.` or `..` parts: the owner's home is the only place it can lead. */
	private function plain(string $folder): bool {
		foreach (explode('/', $folder) as $part) {
			if ($part === '' || $part === '.' || $part === '..') {
				return false;
			}
		}
		return true;
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
