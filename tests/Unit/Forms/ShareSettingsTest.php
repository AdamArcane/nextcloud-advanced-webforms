<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\ShareSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShareSettingsTest extends TestCase {
	#[DataProvider('goodSlugs')]
	public function testAcceptsACustomAddress(string $slug): void {
		$this->assertNull(ShareSettings::slugError($slug));
	}

	/** @return iterable<string, array{string}> */
	public static function goodSlugs(): iterable {
		yield 'word' => ['contact'];
		yield 'hyphens' => ['summer-camp-2027'];
		yield 'digits first' => ['2027-signup'];
		yield 'shortest' => ['abc'];
		yield 'longest' => [str_repeat('a', 64)];
		// The same shape as a random token is fine: the token is looked up first.
		yield 'token shaped' => [str_repeat('a1', 16)];
	}

	#[DataProvider('badSlugs')]
	public function testRefusesACustomAddress(string $slug, string $code): void {
		$this->assertSame($code, ShareSettings::slugError($slug));
	}

	/** @return iterable<string, array{string, string}> */
	public static function badSlugs(): iterable {
		yield 'empty' => ['', 'slug_invalid'];
		yield 'too short' => ['ab', 'slug_invalid'];
		yield 'too long' => [str_repeat('a', 65), 'slug_invalid'];
		yield 'capital' => ['Contact', 'slug_invalid'];
		yield 'space' => ['contact us', 'slug_invalid'];
		yield 'underscore' => ['contact_us', 'slug_invalid'];
		yield 'slash' => ['contact/us', 'slug_invalid'];
		yield 'dot' => ['contact.us', 'slug_invalid'];
		yield 'leading hyphen' => ['-contact', 'slug_invalid'];
		yield 'trailing hyphen' => ['contact-', 'slug_invalid'];
		yield 'double hyphen' => ['contact--us', 'slug_invalid'];
		yield 'trailing newline' => ["contact\n", 'slug_invalid'];
		yield 'non ascii' => ['kontaktformulär', 'slug_invalid'];
		yield 'reserved' => ['embed', 'slug_reserved'];
		yield 'reserved api' => ['api', 'slug_reserved'];
	}

	public function testOriginsAreNormalized(): void {
		$this->assertSame(
			['https://example.com', 'https://*.example.org', 'http://localhost:8080'],
			ShareSettings::normalizeOrigins([' HTTPS://Example.com/ ', 'https://*.example.org', '', 'https://example.com', 'http://localhost:8080']),
		);
		$this->assertSame([], ShareSettings::normalizeOrigins([]));
	}

	#[DataProvider('badOrigins')]
	public function testRefusesWhatIsNotAnOrigin(mixed $origin): void {
		$this->assertNull(ShareSettings::normalizeOrigins(['https://example.com', $origin]));
	}

	/** @return iterable<string, array{mixed}> */
	public static function badOrigins(): iterable {
		yield 'no scheme' => ['example.com'];
		yield 'path' => ['https://example.com/page'];
		yield 'query' => ['https://example.com?x=1'];
		yield 'other scheme' => ['javascript://example.com'];
		yield 'bare wildcard' => ['*'];
		yield 'wildcard in the middle' => ['https://www.*.example.com'];
		yield 'second directive' => ["https://example.com; script-src 'unsafe-inline'"];
		yield 'space' => ['https://example.com https://evil.example'];
		yield 'quote' => ["'self'"];
		yield 'newline' => ["https://example.com\nX-Header: 1"];
		yield 'port too long' => ['https://example.com:123456'];
		yield 'not a string' => [42];
	}

	public function testRefusesTooManyOrigins(): void {
		$origins = [];
		for ($i = 0; $i <= ShareSettings::MAX_ORIGINS; $i++) {
			$origins[] = 'https://site' . $i . '.example.com';
		}
		$this->assertNull(ShareSettings::normalizeOrigins($origins));
		array_pop($origins);
		$this->assertCount(ShareSettings::MAX_ORIGINS, ShareSettings::normalizeOrigins($origins) ?? []);
	}

	public function testStoredOriginsThatWouldNotPassAreLeftOut(): void {
		$this->assertSame(['https://example.com'], ShareSettings::decodeOrigins('["https://example.com","*; default-src *",7]'));
		$this->assertSame([], ShareSettings::decodeOrigins(null));
		$this->assertSame([], ShareSettings::decodeOrigins('not json'));
	}
}
