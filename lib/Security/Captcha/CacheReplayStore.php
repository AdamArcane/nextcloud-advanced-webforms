<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Security\Captcha;

use OCA\AdvancedForms\AppInfo\Application;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Uses Nextcloud's distributed cache. Without a shared cache the check is best effort; a
 * solution still expires after a few minutes and the submit endpoint is rate limited.
 */
final class CacheReplayStore implements ReplayStore {
	private ?ICache $cache = null;

	public function __construct(
		private readonly ICacheFactory $factory,
	) {
	}

	public function markUsed(string $id, int $ttlSeconds): bool {
		$this->cache ??= $this->factory->createDistributed(Application::APP_ID . '-captcha');
		return $this->cache->add($id, '1', $ttlSeconds);
	}
}
