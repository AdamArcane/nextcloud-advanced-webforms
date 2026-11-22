<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Settings;

use OCA\Webforms\Settings\TemplateSettings;
use PHPUnit\Framework\TestCase;

class TemplateSettingsTest extends TestCase {
	public function testItKeepsStringsOnceEach(): void {
		$this->assertSame(['b:contact', 'b:feedback'], TemplateSettings::clean(['b:contact', 7, null, 'b:contact', ['x'], 'b:feedback'], null));
	}

	public function testItKeepsOnlyKnownIdsWhenGivenTheList(): void {
		$this->assertSame(['b:contact'], TemplateSettings::clean(['b:contact', 'b:gone', 'u:1'], ['b:contact', 'b:feedback']));
	}

	public function testNothingHiddenIsTheEmptyList(): void {
		$this->assertSame([], TemplateSettings::clean([], ['b:contact']));
	}
}
