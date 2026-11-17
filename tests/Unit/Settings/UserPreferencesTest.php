<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Settings;

use OCA\Webforms\Settings\UserPreferences;
use PHPUnit\Framework\TestCase;

/**
 * Reading and saving go through Nextcloud's user config, which the unit tests do not have. What decides
 * whether a change is accepted does not need it.
 */
class UserPreferencesTest extends TestCase {
	public function testEveryPreferenceAcceptsItsOwnKindOfValue(): void {
		$this->assertSame([], UserPreferences::invalid([
			'openPublishedOnResponses' => false,
			'newFormLabelPosition' => 'hidden',
			'newFormAccentColor' => '',
			'newFormHideTitle' => true,
			'newFormCaptcha' => false,
			'responsesView' => 'cards',
			'responsesSummary' => true,
		]));
		$this->assertSame([], UserPreferences::invalid(['newFormAccentColor' => '#AA00ff']));
		$this->assertSame([], UserPreferences::invalid([]));
	}

	public function testWhatIsInvalidIsNamedAndNothingElse(): void {
		$this->assertSame(
			['newFormLabelPosition', 'newFormAccentColor', 'newFormHideTitle', 'responsesView', 'responsesSummary', 'nothing'],
			UserPreferences::invalid([
				'newFormLabelPosition' => 'sideways',
				'newFormAccentColor' => 'red',
				'newFormHideTitle' => 'yes',
				'newFormCaptcha' => true,
				'responsesView' => '',
				'responsesSummary' => 1,
				'nothing' => true,
			]),
		);
		$this->assertSame(['newFormAccentColor'], UserPreferences::invalid(['newFormAccentColor' => '#12345']));
		$this->assertSame(['newFormAccentColor'], UserPreferences::invalid(['newFormAccentColor' => '#12345g']));
		$this->assertSame(['newFormAccentColor'], UserPreferences::invalid(['newFormAccentColor' => ['#112233']]));
	}
}
