<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Uploads\Ports;

interface MimePort {
	/** The type of the file's content, not of its name. */
	public function detect(string $localPath, string $name): string;
}
