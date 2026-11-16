<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Actions\Ports;

/**
 * A file in the form owner's Nextcloud Files that responses are added to (docs/SCHEMA.md section 19).
 * Everything here acts as the owner, so it can only reach what the owner can reach. Paths are relative
 * to the owner's home and already cleaned (CsvFile::cleanPath).
 */
interface ResponseFilesPort {
	/** Whether the owner may change the file, or create it in the nearest folder that exists. */
	public function canWrite(string $ownerId, string $path): bool;

	/**
	 * Runs `$work` while holding this app's lock on the file, so two deliveries never write it at once.
	 *
	 * @param callable(): void $work
	 * @throws FilesFailure `files_busy` when another delivery holds it
	 */
	public function locked(string $ownerId, string $path, callable $work): void;

	/**
	 * The file's contents, or null when there is no file yet.
	 *
	 * @throws FilesFailure
	 */
	public function read(string $ownerId, string $path, int $maxBytes): ?string;

	/**
	 * Replaces the file's contents, creating it and the folders on the way when needed.
	 *
	 * @throws FilesFailure
	 */
	public function write(string $ownerId, string $path, string $content): void;
}
