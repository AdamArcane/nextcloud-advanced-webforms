<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Security;

use OCA\Webforms\Security\FillTimeToken;
use PHPUnit\Framework\TestCase;

class FillTimeTokenTest extends TestCase {
	private function tokens(): FillTimeToken {
		return new FillTimeToken('instance-secret', 3, 3600);
	}

	public function testAcceptedWithinWindow(): void {
		$token = $this->tokens()->issue(7, 9, 1000);
		$this->assertSame('ok', $this->tokens()->check($token, 7, 9, 1010));
	}

	public function testTooFast(): void {
		$token = $this->tokens()->issue(7, 9, 1000);
		$this->assertSame('too_fast', $this->tokens()->check($token, 7, 9, 1002));
	}

	public function testExpired(): void {
		$token = $this->tokens()->issue(7, 9, 1000);
		$this->assertSame('expired', $this->tokens()->check($token, 7, 9, 1000 + 3601));
	}

	public function testBoundToFormAndVersion(): void {
		$token = $this->tokens()->issue(7, 9, 1000);
		$this->assertSame('invalid', $this->tokens()->check($token, 8, 9, 1010));
		$this->assertSame('invalid', $this->tokens()->check($token, 7, 10, 1010));
	}

	public function testBoundToSecret(): void {
		$token = (new FillTimeToken('other-secret'))->issue(7, 9, 1000);
		$this->assertSame('invalid', $this->tokens()->check($token, 7, 9, 1010));
	}

	public function testTimestampCannotBeEdited(): void {
		[, $signature] = explode('.', $this->tokens()->issue(7, 9, 1000));
		$this->assertSame('invalid', $this->tokens()->check('1.' . $signature, 7, 9, 1010));
	}

	public function testGarbageIsInvalid(): void {
		foreach (['', 'abc', '1000.', '1000.zz', str_repeat('1', 40)] as $garbage) {
			$this->assertSame('invalid', $this->tokens()->check($garbage, 7, 9, 1010));
		}
	}
}
