<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Uploads\Ports;

/** Where file contents live: pending uploads, and files kept in the app's own storage. */
interface PendingStorePort {
	/** @param string $localPath a file on disk, such as PHP's upload temp file */
	public function put(string $token, string $localPath): void;

	/** @return resource|null */
	public function read(string $token);

	public function delete(string $token): void;
}
