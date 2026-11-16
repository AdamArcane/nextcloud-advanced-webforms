<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Uploads\Ports;

/**
 * Pictures in the form owner's Files, read as the owner, for image blocks (docs/SCHEMA.md section 4.9).
 */
interface OwnerImagePort {
	/**
	 * The picture at this path below the owner's home, or null when there is no file there, it is too
	 * large, or the owner may not read it.
	 *
	 * @return array{size: int, mtime: int, stream: resource}|null
	 */
	public function open(string $ownerId, string $path): ?array;

	/** Whether open() would find a picture, without opening it. */
	public function exists(string $ownerId, string $path): bool;
}
