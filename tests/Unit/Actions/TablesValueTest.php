<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Actions;

use OCA\Webforms\Actions\TablesValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TablesValueTest extends TestCase {
	private const META = ['submittedAt' => 1794700000, 'userId' => 'ada', 'entryId' => 457];

	/** @return array<string, array{array<string, mixed>, ?string}> */
	public static function kinds(): array {
		return [
			'line' => [['type' => 'text', 'subtype' => 'line'], 'text'],
			'rich' => [['type' => 'text', 'subtype' => 'rich'], 'text'],
			'text without subtype' => [['type' => 'text'], 'text'],
			'number' => [['type' => 'number', 'subtype' => ''], 'number'],
			'stars' => [['type' => 'number', 'subtype' => 'stars'], 'stars'],
			'progress prefixed' => [['type' => 'number', 'subtype' => 'number-progress'], 'progress'],
			'single' => [['type' => 'selection', 'subtype' => ''], 'single'],
			'multi' => [['type' => 'selection', 'subtype' => 'multi'], 'multi'],
			'multi prefixed' => [['type' => 'selection', 'subtype' => 'selection-multi'], 'multi'],
			'check' => [['type' => 'selection', 'subtype' => 'check'], 'check'],
			'datetime' => [['type' => 'datetime', 'subtype' => ''], 'datetime'],
			'date' => [['type' => 'datetime', 'subtype' => 'date'], 'date'],
			'time prefixed' => [['type' => 'datetime', 'subtype' => 'datetime-time'], 'time'],
			'people' => [['type' => 'usergroup', 'subtype' => ''], null],
			'relation' => [['type' => 'relation', 'subtype' => ''], null],
		];
	}

	/**
	 * @param array<string, mixed> $column
	 */
	#[DataProvider('kinds')]
	public function testKind(array $column, ?string $kind): void {
		$this->assertSame($kind, TablesValue::kind($column));
	}

	public function testCompatibility(): void {
		$text = ['type' => 'text', 'subtype' => 'line'];
		$this->assertTrue(TablesValue::compatible('email', $text));
		$this->assertTrue(TablesValue::compatible('checkboxes', $text));
		$this->assertTrue(TablesValue::compatible('file', $text));
		$this->assertFalse(TablesValue::compatible('paragraph', $text));
		$this->assertTrue(TablesValue::compatible('@respondent', $text));

		$number = ['type' => 'number'];
		$this->assertTrue(TablesValue::compatible('number', $number));
		$this->assertFalse(TablesValue::compatible('text', $number));
		$this->assertTrue(TablesValue::compatible('@entry', $number));
		$this->assertFalse(TablesValue::compatible('@submitted', $number));

		$this->assertTrue(TablesValue::compatible('select', ['type' => 'selection']));
		$this->assertTrue(TablesValue::compatible('radio', ['type' => 'selection']));
		$this->assertFalse(TablesValue::compatible('checkboxes', ['type' => 'selection']));
		$this->assertTrue(TablesValue::compatible('checkboxes', ['type' => 'selection', 'subtype' => 'multi']));
		$this->assertTrue(TablesValue::compatible('checkbox', ['type' => 'selection', 'subtype' => 'check']));
		$this->assertTrue(TablesValue::compatible('date', ['type' => 'datetime', 'subtype' => 'date']));
		$this->assertTrue(TablesValue::compatible('@submitted', ['type' => 'datetime']));
		$this->assertFalse(TablesValue::compatible('time', ['type' => 'datetime', 'subtype' => 'date']));
		$this->assertFalse(TablesValue::compatible('text', ['type' => 'usergroup']));
	}

	public function testTextTurnsChoicesIntoLabelsAndFilesIntoNames(): void {
		$text = ['type' => 'text', 'subtype' => 'line'];
		$choice = ['type' => 'select', 'options' => [['value' => 'opt_a', 'label' => 'New Website'], ['value' => 'opt_b', 'label' => 'Redesign']]];
		$this->assertSame('New Website', TablesValue::convert('select', $choice, 'opt_a', $text, self::META));
		$this->assertSame('New Website, Redesign', TablesValue::convert('checkboxes', $choice, ['opt_a', 'opt_b'], $text, self::META));
		$this->assertSame('Yes', TablesValue::convert('checkbox', [], true, $text, self::META));
		$this->assertSame('cv.pdf, id.png', TablesValue::convert('file', [], [['name' => 'cv.pdf', 'path' => '/x'], ['name' => 'id.png']], $text, self::META));
		$this->assertSame('Ada', TablesValue::convert('text', [], 'Ada', $text, self::META));
	}

	public function testUnansweredIsNothingToWrite(): void {
		$text = ['type' => 'text'];
		$this->assertNull(TablesValue::convert('text', [], null, $text, self::META));
		$this->assertNull(TablesValue::convert('text', [], '', $text, self::META));
		$this->assertNull(TablesValue::convert('checkboxes', [], [], $text, self::META));
	}

	public function testNumbers(): void {
		$this->assertSame(42, TablesValue::convert('number', [], 42, ['type' => 'number'], self::META));
		$this->assertSame(4.5, TablesValue::convert('number', [], 4.5, ['type' => 'number'], self::META));
		$this->assertSame(4, TablesValue::convert('number', [], 3.6, ['type' => 'number', 'subtype' => 'stars'], self::META));
		$this->assertNull(TablesValue::convert('number', [], 'abc', ['type' => 'number'], self::META));
	}

	public function testSelectionsMatchOptionsByLabel(): void {
		$column = ['type' => 'selection', 'subtype' => '', 'options' => [['id' => 7, 'label' => 'new website'], ['id' => 8, 'label' => 'Redesign']]];
		$field = ['type' => 'select', 'options' => [['value' => 'opt_a', 'label' => 'New Website'], ['value' => 'opt_z', 'label' => 'Other']]];
		$this->assertSame(7, TablesValue::convert('select', $field, 'opt_a', $column, self::META));
		// No option with that label in the column: nothing is written.
		$this->assertNull(TablesValue::convert('select', $field, 'opt_z', $column, self::META));

		$multi = ['type' => 'selection', 'subtype' => 'multi', 'options' => $column['options']];
		$boxes = ['type' => 'checkboxes', 'options' => [['value' => 'a', 'label' => 'New Website'], ['value' => 'b', 'label' => 'Redesign'], ['value' => 'c', 'label' => 'Nope']]];
		$this->assertSame([7, 8], TablesValue::convert('checkboxes', $boxes, ['a', 'b', 'c'], $multi, self::META));
		$this->assertNull(TablesValue::convert('checkboxes', $boxes, ['c'], $multi, self::META));
	}

	public function testCheckboxDatesAndMeta(): void {
		$check = ['type' => 'selection', 'subtype' => 'check'];
		$this->assertTrue(TablesValue::convert('checkbox', [], true, $check, self::META));
		$this->assertFalse(TablesValue::convert('checkbox', [], false, $check, self::META));
		$this->assertSame('2026-12-01', TablesValue::convert('date', [], '2026-12-01', ['type' => 'datetime', 'subtype' => 'date'], self::META));
		$this->assertSame('2026-12-01 00:00', TablesValue::convert('date', [], '2026-12-01', ['type' => 'datetime'], self::META));
		$this->assertSame('09:30', TablesValue::convert('time', [], '09:30', ['type' => 'datetime', 'subtype' => 'time'], self::META));

		$this->assertSame('2026-11-14 23:46', TablesValue::convert('@submitted', [], null, ['type' => 'datetime'], self::META));
		$this->assertSame('2026-11-14', TablesValue::convert('@submitted', [], null, ['type' => 'datetime', 'subtype' => 'date'], self::META));
		$this->assertSame('2026-11-14T23:46:40Z', TablesValue::convert('@submitted', [], null, ['type' => 'text'], self::META));
		$this->assertSame('ada', TablesValue::convert('@respondent', [], null, ['type' => 'text'], self::META));
		$this->assertSame(457, TablesValue::convert('@entry', [], null, ['type' => 'number'], self::META));
		$this->assertSame('457', TablesValue::convert('@entry', [], null, ['type' => 'text'], self::META));
	}

	public function testIncompatibleSourcesAreNotWritten(): void {
		$this->assertNull(TablesValue::convert('text', [], 'x', ['type' => 'usergroup'], self::META));
		$this->assertNull(TablesValue::convert('text', [], 'x', ['type' => 'number'], self::META));
	}
}
