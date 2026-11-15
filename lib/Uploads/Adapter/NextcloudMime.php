<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Uploads\Adapter;

use OCA\Webforms\Uploads\Ports\MimePort;
use OCP\Files\IMimeTypeDetector;

final class NextcloudMime implements MimePort {
	public function __construct(
		private readonly IMimeTypeDetector $detector,
	) {
	}

	public function detect(string $localPath, string $name): string {
		$mime = $this->detector->detectContent($localPath);
		// Content sniffing gives up on some formats (for example office files); fall back to the name.
		return $mime === '' || $mime === 'application/octet-stream' ? $this->detector->detectPath($name) : $mime;
	}
}
