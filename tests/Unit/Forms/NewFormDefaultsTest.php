<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\DefinitionValidator;
use OCA\Webforms\Forms\NewFormDefaults;
use PHPUnit\Framework\TestCase;

class NewFormDefaultsTest extends TestCase {
	public function testNothingSetGivesEmptySettings(): void {
		$this->assertSame([], NewFormDefaults::settings([]));
		$this->assertSame([], NewFormDefaults::settings([
			'newFormLabelPosition' => 'above', 'newFormAccentColor' => '', 'newFormHideTitle' => false, 'newFormCaptcha' => true,
		]));
	}

	public function testOnlyWhatDiffersIsWritten(): void {
		$this->assertSame(['labelPosition' => 'inline'], NewFormDefaults::settings(['newFormLabelPosition' => 'inline']));
		$this->assertSame(['hideTitle' => true], NewFormDefaults::settings(['newFormHideTitle' => true]));
		$this->assertSame(['captcha' => false], NewFormDefaults::settings(['newFormCaptcha' => false]));
		$this->assertSame(['design' => ['accentColor' => '#aa00ff']], NewFormDefaults::settings(['newFormAccentColor' => '#AA00FF']));
	}

	public function testEverythingTogether(): void {
		$this->assertSame(
			['labelPosition' => 'hidden', 'hideTitle' => true, 'captcha' => false, 'design' => ['accentColor' => '#112233']],
			NewFormDefaults::settings(['newFormLabelPosition' => 'hidden', 'newFormHideTitle' => true, 'newFormCaptcha' => false, 'newFormAccentColor' => '#112233']),
		);
	}

	public function testAnOddValueIsNeverWritten(): void {
		$this->assertSame([], NewFormDefaults::settings(['newFormLabelPosition' => 'sideways', 'newFormAccentColor' => 'red', 'newFormHideTitle' => 'yes', 'newFormCaptcha' => 'no']));
	}

	public function testTheSettingsAreOnesTheDraftValidatorAccepts(): void {
		$settings = NewFormDefaults::settings(['newFormLabelPosition' => 'inline', 'newFormHideTitle' => true, 'newFormCaptcha' => false, 'newFormAccentColor' => '#0082c9']);
		$definition = [
			'schemaVersion' => 1,
			'title' => 'New',
			'settings' => $settings,
			'fields' => new \stdClass(),
			'pages' => [['id' => 'p_1', 'title' => null, 'rows' => []]],
			'rules' => [],
			'actions' => [],
		];
		$errors = array_filter(
			(new DefinitionValidator())->validate(json_decode((string)json_encode($definition), true)),
			static fn (array $error): bool => str_starts_with($error['path'], 'settings'),
		);
		$this->assertSame([], array_values($errors));
	}
}
