<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Forms\TemplateDescription;
use PHPUnit\Framework\TestCase;

class TemplateDescriptionTest extends TestCase {
	public function testGivenTextIsKeptTrimmed(): void {
		$this->assertSame('For the front desk.', TemplateDescription::resolve('  For the front desk. ', ['description' => 'Other']));
	}

	public function testGivenEmptyMeansNone(): void {
		$this->assertSame('', TemplateDescription::resolve('', ['description' => 'The form description']));
		$this->assertSame('', TemplateDescription::resolve('   ', ['description' => 'The form description']));
	}

	public function testLeftOutUsesTheFormsOwnDescriptionOnOneLine(): void {
		$this->assertSame('First line. Second line.', TemplateDescription::resolve(null, ['description' => "First line.\n\n  Second   line.\n"]));
	}

	public function testLeftOutWithNoDescriptionOnTheFormIsEmpty(): void {
		$this->assertSame('', TemplateDescription::resolve(null, []));
		$this->assertSame('', TemplateDescription::resolve(null, ['description' => null]));
		$this->assertSame('', TemplateDescription::resolve(null, ['description' => ['not', 'text']]));
	}

	public function testALongFormDescriptionIsCutToALine(): void {
		$result = TemplateDescription::resolve(null, ['description' => str_repeat('word ', 100)]);
		$this->assertLessThanOrEqual(TemplateDescription::DEFAULT_LENGTH, mb_strlen($result));
		$this->assertStringEndsWith('...', $result);
	}

	public function testMultibyteTextIsCutByCharacters(): void {
		$result = TemplateDescription::resolve(null, ['description' => str_repeat('é', 400)]);
		$this->assertSame(TemplateDescription::DEFAULT_LENGTH, mb_strlen($result));
	}

	public function testTooLongIsRefused(): void {
		$this->expectException(ValidationException::class);
		TemplateDescription::resolve(str_repeat('a', TemplateDescription::MAX_LENGTH + 1), []);
	}

	public function testExactlyTheLimitIsAccepted(): void {
		$text = str_repeat('a', TemplateDescription::MAX_LENGTH);
		$this->assertSame($text, TemplateDescription::resolve($text, []));
	}
}
