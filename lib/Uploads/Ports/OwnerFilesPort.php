<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Uploads\Ports;

/**
 * The form owner's Nextcloud Files. Everything here acts as the owner, so it can only reach
 * what the owner can reach.
 */
interface OwnerFilesPort {
	/** Whether the owner may create files in this folder (or in its nearest existing parent). */
	public function canWrite(string $ownerId, string $folder): bool;

	/** Whether the owner may share this folder by public link, which is what an anonymous upload amounts to. */
	public function canShareByLink(string $ownerId, string $folder): bool;

	/**
	 * Save a file as `<folder>/<subfolder>/<name>` and return its Nextcloud file id.
	 *
	 * @param resource $stream
	 * @throws \Throwable when the owner has no room or no permission
	 */
	public function store(string $ownerId, string $folder, string $subfolder, string $name, $stream): int;

	/**
	 * @return array{name: string, size: int, mime: string, stream: resource}|null
	 */
	public function open(string $ownerId, int $fileId): ?array;

	public function delete(string $ownerId, int $fileId): void;
}
