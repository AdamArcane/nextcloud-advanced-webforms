<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions\Adapter;

use OCA\Webforms\Actions\Ports\FilesFailure;
use OCA\Webforms\Actions\Ports\ResponseFilesPort;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Works in the form owner's Files, as the owner, so Nextcloud decides what is allowed and its quota,
 * locking and versions apply.
 */
final class NextcloudResponseFiles implements ResponseFilesPort {
	public function __construct(
		private readonly IRootFolder $root,
		private readonly ILockingProvider $locks,
	) {
	}

	public function canWrite(string $ownerId, string $path): bool {
		try {
			$home = $this->root->getUserFolder($ownerId);
			if ($home->nodeExists($path)) {
				$node = $home->get($path);
				return $node instanceof File && $node->isUpdateable();
			}
			$parent = dirname($path);
			$node = $parent === '.' ? $home : $this->nearestExisting($home, $parent);
			return $node instanceof Folder && $node->isCreatable();
		} catch (\Throwable) {
			return false;
		}
	}

	public function locked(string $ownerId, string $path, callable $work): void {
		// This app's own key, not the file's: Nextcloud takes its own locks on the file while writing.
		$key = 'webforms/response-file/' . $ownerId . '/' . $path;
		try {
			$this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			throw new FilesFailure('files_busy', true);
		}
		try {
			$work();
		} finally {
			$this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function read(string $ownerId, string $path, int $maxBytes): ?string {
		try {
			$home = $this->root->getUserFolder($ownerId);
			if (!$home->nodeExists($path)) {
				return null;
			}
			$node = $home->get($path);
			if (!$node instanceof File) {
				throw new FilesFailure('files_not_a_file');
			}
			if (!$node->isReadable() || !$node->isUpdateable()) {
				throw new FilesFailure('files_not_writable');
			}
			if ($node->getSize() > $maxBytes) {
				throw new FilesFailure('files_too_large');
			}
			return $node->getContent();
		} catch (\Throwable $e) {
			throw self::failure($e);
		}
	}

	public function write(string $ownerId, string $path, string $content): void {
		try {
			$home = $this->root->getUserFolder($ownerId);
			if ($home->nodeExists($path)) {
				$node = $home->get($path);
				if (!$node instanceof File) {
					throw new FilesFailure('files_not_a_file');
				}
				$node->putContent($content);
				return;
			}
			$parent = dirname($path);
			$dir = $parent === '.' ? $home : $this->ensureFolder($home, $parent);
			$dir->newFile(basename($path), $content);
		} catch (\Throwable $e) {
			throw self::failure($e);
		}
	}

	private static function failure(\Throwable $e): FilesFailure {
		return match (true) {
			$e instanceof FilesFailure => $e,
			$e instanceof LockedException => new FilesFailure('files_busy', true),
			$e instanceof NotEnoughSpaceException => new FilesFailure('files_storage_full', true),
			$e instanceof NotPermittedException => new FilesFailure('files_not_writable'),
			default => new FilesFailure('files_error', true, mb_substr($e->getMessage(), 0, 200)),
		};
	}

	/** The deepest part of the path that exists already: that is where permissions are decided. */
	private function nearestExisting(Folder $home, string $folder): Node {
		$node = $home;
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
					throw new FilesFailure('files_not_a_file');
				}
				$dir = $next;
			} else {
				$dir = $dir->newFolder($part);
			}
		}
		return $dir;
	}
}
