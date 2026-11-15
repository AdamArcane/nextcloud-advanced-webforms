<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Uploads\Adapter;

use OCA\Webforms\Uploads\Ports\PendingStorePort;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Uploaded files, in the app's own data folder (`appdata_<instance>/webforms/uploads`). They
 * are named by their unguessable token and never served from here: a download goes through the
 * owner's authenticated request.
 */
final class NextcloudPendingStore implements PendingStorePort {
	public function __construct(
		private readonly IAppData $appData,
	) {
	}

	public function put(string $token, string $localPath): void {
		$stream = fopen($localPath, 'rb');
		if ($stream === false) {
			throw new \RuntimeException('Cannot read the uploaded file');
		}
		try {
			$this->folder()->newFile($token, $stream);
		} finally {
			// Nextcloud closes a stream it was given once it has written it.
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
	}

	public function read(string $token) {
		try {
			$stream = $this->folder()->getFile($token)->read();
		} catch (NotFoundException) {
			return null;
		}
		return is_resource($stream) ? $stream : null;
	}

	public function delete(string $token): void {
		try {
			$this->folder()->getFile($token)->delete();
		} catch (NotFoundException) {
			// Already gone.
		}
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder('uploads');
		} catch (NotFoundException) {
			return $this->appData->newFolder('uploads');
		}
	}
}
