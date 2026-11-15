<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Security\Captcha;

/** Remembers solved challenges for a while so one solution cannot be reused. */
interface ReplayStore {
	/**
	 * @return bool true the first time an id is seen, false if it was already used
	 */
	public function markUsed(string $id, int $ttlSeconds): bool;
}
