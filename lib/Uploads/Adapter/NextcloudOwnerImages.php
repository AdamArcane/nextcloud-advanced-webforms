<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Uploads\Adapter;

use OCA\Webforms\Forms\ImageSource;
use OCA\Webforms\Uploads\Ports\OwnerImagePort;
use OCP\Files\File;
use OCP\Files\IRootFolder;

/**
 * Reads from the owner's own view of their Files, so it reaches only what the owner can reach. The
 * path has been through ImageSource::filesPath(), which leaves no way out of the home folder.
 */
final class NextcloudOwnerImages implements OwnerImagePort {
	public function __construct(
		private readonly IRootFolder $root,
	) {
	}

	public function open(string $ownerId, string $path): ?array {
		$file = $this->file($ownerId, $path);
		if ($file === null) {
			return null;
		}
		$stream = $file->fopen('r');
		return is_resource($stream) ? ['size' => (int)$file->getSize(), 'mtime' => $file->getMTime(), 'stream' => $stream] : null;
	}

	public function exists(string $ownerId, string $path): bool {
		return $this->file($ownerId, $path) !== null;
	}

	private function file(string $ownerId, string $path): ?File {
		try {
			$node = $this->root->getUserFolder($ownerId)->get($path);
		} catch (\Throwable) {
			return null;
		}
		return $node instanceof File && $node->isReadable() && $node->getSize() <= ImageSource::MAX_BYTES ? $node : null;
	}
}
