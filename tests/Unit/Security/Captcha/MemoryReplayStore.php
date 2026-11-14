<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Tests\Unit\Security\Captcha;

use OCA\AdvancedForms\Security\Captcha\ReplayStore;

final class MemoryReplayStore implements ReplayStore {
	/** @var array<string, true> */
	private array $used = [];

	public function markUsed(string $id, int $ttlSeconds): bool {
		if (isset($this->used[$id])) {
			return false;
		}
		$this->used[$id] = true;
		return true;
	}
}
