<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Security;

use OCA\Webforms\Security\AccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccessTokenTest extends TestCase {
	private const HASH = '3|$argon2id$v=19$m=65536,t=4,p=1$abc$def';

	public function testAFreshTokenIsAccepted(): void {
		$tokens = new AccessToken('secret');
		$token = $tokens->issue(7, self::HASH, 1000);
		self::assertTrue($tokens->check($token, 7, self::HASH, 1001));
	}

	public function testItStopsWorkingAfterItsLifetime(): void {
		$tokens = new AccessToken('secret', 100);
		$token = $tokens->issue(7, self::HASH, 1000);
		self::assertTrue($tokens->check($token, 7, self::HASH, 1100));
		self::assertFalse($tokens->check($token, 7, self::HASH, 1101));
	}

	public function testItIsOnlyGoodForTheFormItWasIssuedFor(): void {
		$tokens = new AccessToken('secret');
		$token = $tokens->issue(7, self::HASH, 1000);
		self::assertFalse($tokens->check($token, 8, self::HASH, 1001));
	}

	public function testChangingThePasswordEndsEveryTokenIssuedBefore(): void {
		$tokens = new AccessToken('secret');
		$token = $tokens->issue(7, self::HASH, 1000);
		self::assertFalse($tokens->check($token, 7, self::HASH . 'x', 1001));
	}

	public function testAnotherServerSecretDoesNotAcceptIt(): void {
		$token = (new AccessToken('secret'))->issue(7, self::HASH, 1000);
		self::assertFalse((new AccessToken('other'))->check($token, 7, self::HASH, 1001));
	}

	public function testALaterExpiryCannotBeWrittenIn(): void {
		$tokens = new AccessToken('secret', 100);
		[, $signature] = explode('.', $tokens->issue(7, self::HASH, 1000));
		self::assertFalse($tokens->check('9999999999.' . $signature, 7, self::HASH, 1200));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function malformed(): iterable {
		yield 'empty' => [''];
		yield 'no dot' => ['12345'];
		yield 'short signature' => ['12345.abcd'];
		yield 'not hex' => ['12345.' . str_repeat('z', 64)];
		yield 'negative' => ['-1.' . str_repeat('a', 64)];
	}

	#[DataProvider('malformed')]
	public function testMalformedTokensAreRefused(string $token): void {
		self::assertFalse((new AccessToken('secret'))->check($token, 7, self::HASH, 1000));
	}
}
