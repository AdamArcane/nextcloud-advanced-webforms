<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Uploads\Ports;

use OCA\Webforms\Uploads\PendingUpload;

/** The list of pending uploads (a database table in the app). */
interface UploadRegistryPort {
	public function add(PendingUpload $upload): void;

	public function find(string $token): ?PendingUpload;

	public function remove(string $token): void;

	/** Total size of the uploads waiting for a form. */
	public function pendingBytes(int $formId): int;

	/** @return list<PendingUpload> every upload still waiting for a form */
	public function forForm(int $formId): array;

	/** @return list<PendingUpload> waiting uploads created before `$olderThan`, oldest first */
	public function expired(int $olderThan, int $limit): array;
}
