<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Actions;

use OCA\Webforms\Actions\Backoff;
use PHPUnit\Framework\TestCase;

class BackoffTest extends TestCase {
	public function testWaitsLongerEachTimeThenGivesUp(): void {
		$this->assertSame(60, Backoff::delayAfter(1));
		$this->assertSame(300, Backoff::delayAfter(2));
		$this->assertSame(1800, Backoff::delayAfter(3));
		$this->assertSame(7200, Backoff::delayAfter(4));
		$this->assertNull(Backoff::delayAfter(5));
		$this->assertNull(Backoff::delayAfter(50));
	}

	public function testFiveAttemptsInAll(): void {
		$tries = 1;
		while (Backoff::delayAfter($tries) !== null) {
			$tries++;
		}
		$this->assertSame(Backoff::MAX_ATTEMPTS, $tries);
	}
}
