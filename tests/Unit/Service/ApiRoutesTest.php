<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * The read-only API only reads (docs/SCHEMA.md section 23). The controller cannot be loaded without a
 * Nextcloud server, so this reads its source: every route is a GET, every route can be rate limited and
 * switched off, and no method of the service behind it writes.
 */
final class ApiRoutesTest extends TestCase {
	private static function source(string $path): string {
		$source = file_get_contents(__DIR__ . '/../../../lib/' . $path);
		self::assertIsString($source);
		return $source;
	}

	public function testEveryRouteIsAGet(): void {
		$source = self::source('Controller/ApiController.php');
		preg_match_all("/#\[ApiRoute\(verb: '([A-Z]+)'/", $source, $found);
		self::assertGreaterThanOrEqual(8, count($found[1]), 'The routes of section 23.1.');
		self::assertSame(['GET'], array_values(array_unique($found[1])));
		self::assertStringNotContainsString('FrontpageRoute', $source, 'This is an OCS API; the app\'s own interface is FormController.');
	}

	public function testEveryRouteIsRateLimitedAndSwitchable(): void {
		$source = self::source('Controller/ApiController.php');
		self::assertSame(substr_count($source, '#[ApiRoute('), substr_count($source, '#[UserRateLimit('), 'Every route has a rate limit.');
		self::assertSame(substr_count($source, '#[ApiRoute('), substr_count($source, '$this->answer('), 'Every route goes through answer(), which checks the administrator\'s switch.');
		self::assertStringContainsString('apiEnabled()', $source);
	}

	public function testTheServiceBehindItNeverWrites(): void {
		$source = self::source('Service/ApiService.php');
		foreach (['->insert(', '->update(', '->delete', '->save', '->set', '->transfer', '->publish', '->mark'] as $write) {
			self::assertStringNotContainsString($write, $source, 'ApiService must not call ' . $write);
		}
	}
}
