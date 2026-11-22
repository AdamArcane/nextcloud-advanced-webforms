<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\TemplateText;
use PHPUnit\Framework\TestCase;

class TemplateTextTest extends TestCase {
	/**
	 * @return array<string, mixed>
	 */
	private static function definition(): array {
		return [
			'title' => 'Contact us',
			'settings' => ['submitLabel' => 'Send'],
			'fields' => [
				'name' => ['type' => 'text', 'label' => 'Name'],
				'topic' => ['type' => 'select', 'label' => 'Topic', 'options' => [['value' => 'bug', 'label' => 'Name']]],
			],
			'pages' => [['id' => 'p_1', 'title' => null, 'rows' => []]],
		];
	}

	public function testItTranslatesWordsRespondentsRead(): void {
		$out = TemplateText::translate(self::definition(), ['Contact us' => 'Kontakt', 'Send' => 'Senden', 'Name' => 'Navn']);
		$this->assertSame('Kontakt', $out['title']);
		$this->assertSame('Senden', $out['settings']['submitLabel']);
		$this->assertSame('Navn', $out['fields']['topic']['options'][0]['label']);
		$this->assertSame('Navn', $out['fields']['name']['label']);
	}

	public function testItNeverChangesAnIdATypeOrAValue(): void {
		$out = TemplateText::translate(self::definition(), ['text' => 'x', 'select' => 'y', 'bug' => 'z', 'p_1' => 'q', 'name' => 'w']);
		$this->assertSame(self::definition(), $out);
	}

	public function testBadEntriesAreIgnored(): void {
		$out = TemplateText::translate(self::definition(), ['Send' => '', 'Name' => ['x'], 7 => 'seven', 'Topic' => str_repeat('a', 1001)]);
		$this->assertSame(self::definition(), $out);
	}

	public function testItStopsAtTheLimit(): void {
		$map = [];
		for ($i = 0; $i < TemplateText::MAX_ENTRIES; $i++) {
			$map['filler ' . $i] = 'x';
		}
		$map['Send'] = 'Senden';
		$this->assertSame('Send', TemplateText::translate(self::definition(), $map)['settings']['submitLabel']);
	}
}
