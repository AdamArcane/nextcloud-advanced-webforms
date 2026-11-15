<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Actions;

use OCA\Webforms\Actions\TablesSchema;
use OCA\Webforms\Actions\TablesValue;
use PHPUnit\Framework\TestCase;

final class TablesSchemaTest extends TestCase {
	public function testOneColumnPerFieldThenWhenItWasSubmitted(): void {
		$schema = TablesSchema::forFields([
			['id' => 'f_name', 'type' => 'text', 'label' => 'Full name'],
			['id' => 'f_notes', 'type' => 'textarea', 'label' => 'Notes'],
			['id' => 'f_mail', 'type' => 'email', 'label' => 'Email'],
			['id' => 'f_kind', 'type' => 'select', 'label' => 'Kind', 'options' => [['value' => 'a', 'label' => 'Support'], ['value' => 'b', 'label' => 'support'], ['value' => 'c', 'label' => ' Sales ']]],
			['id' => 'f_tags', 'type' => 'checkboxes', 'label' => 'Tags', 'options' => [['value' => 'x', 'label' => 'One']]],
			['id' => 'f_ok', 'type' => 'checkbox', 'label' => 'Agree'],
			['id' => 'f_when', 'type' => 'date', 'label' => 'Date'],
			['id' => 'f_at', 'type' => 'time', 'label' => 'Time'],
			['id' => 'f_cv', 'type' => 'file', 'label' => 'CV'],
		]);

		$byTitle = array_column($schema['columns'], null, 'title');
		$this->assertSame(['text', 'line'], [$byTitle['Full name']['type'], $byTitle['Full name']['subtype']]);
		$this->assertSame('long', $byTitle['Notes']['subtype']);
		$this->assertSame('line', $byTitle['Email']['subtype']);
		// Options keep their labels, once each, trimmed.
		$this->assertSame(['selection', '', ['Support', 'Sales']], [$byTitle['Kind']['type'], $byTitle['Kind']['subtype'], $byTitle['Kind']['options']]);
		$this->assertSame(['selection', 'multi'], [$byTitle['Tags']['type'], $byTitle['Tags']['subtype']]);
		$this->assertSame('check', $byTitle['Agree']['subtype']);
		$this->assertSame(['datetime', 'date'], [$byTitle['Date']['type'], $byTitle['Date']['subtype']]);
		$this->assertSame(['datetime', 'time'], [$byTitle['Time']['type'], $byTitle['Time']['subtype']]);
		$this->assertSame('line', $byTitle['CV']['subtype']);

		// The last column is when it arrived, and sources line up with columns.
		$this->assertCount(count($schema['columns']), $schema['sources']);
		$this->assertSame(TablesValue::SUBMITTED, end($schema['sources']));
		$this->assertSame(['datetime', 'Submitted'], [end($schema['columns'])['type'], end($schema['columns'])['title']]);
		$this->assertSame(['f_name', 'f_notes', 'f_mail', 'f_kind', 'f_tags', 'f_ok', 'f_when', 'f_at', 'f_cv'], array_slice($schema['sources'], 0, 9));
	}

	public function testEveryColumnIsOptional(): void {
		$schema = TablesSchema::forFields([['id' => 'f_name', 'type' => 'text', 'label' => 'Name', 'required' => true]]);
		foreach ($schema['columns'] as $column) {
			$this->assertFalse($column['mandatory']);
		}
	}

	public function testEveryColumnCanTakeItsSource(): void {
		$fields = [
			['id' => 'f_a', 'type' => 'text', 'label' => 'A'], ['id' => 'f_b', 'type' => 'number', 'label' => 'B'],
			['id' => 'f_c', 'type' => 'select', 'label' => 'C', 'options' => [['value' => 'x', 'label' => 'X']]],
			['id' => 'f_d', 'type' => 'checkboxes', 'label' => 'D', 'options' => [['value' => 'x', 'label' => 'X']]],
			['id' => 'f_e', 'type' => 'checkbox', 'label' => 'E'], ['id' => 'f_f', 'type' => 'date', 'label' => 'F'],
			['id' => 'f_g', 'type' => 'time', 'label' => 'G'], ['id' => 'f_h', 'type' => 'file', 'label' => 'H'],
			['id' => 'f_i', 'type' => 'radio', 'label' => 'I', 'options' => [['value' => 'x', 'label' => 'X']]],
		];
		$types = array_column($fields, 'type', 'id');
		$schema = TablesSchema::forFields($fields);
		foreach ($schema['columns'] as $i => $column) {
			$source = $schema['sources'][$i];
			$this->assertTrue(TablesValue::compatible($types[$source] ?? $source, $column), $source . ' into ' . $column['title']);
		}
	}

	public function testNumberDecimalsFollowTheStepAndPrefixCarriesOver(): void {
		$one = TablesSchema::forFields([['id' => 'f_n', 'type' => 'number', 'label' => 'N', 'step' => 0.25, 'prefix' => '$']])['columns'][0];
		$this->assertSame(2, $one['decimals']);
		$this->assertSame('$', $one['prefix']);
		$this->assertSame(0, TablesSchema::forFields([['id' => 'f_n', 'type' => 'number', 'label' => 'N', 'step' => 1]])['columns'][0]['decimals']);
		$this->assertSame(2, TablesSchema::forFields([['id' => 'f_n', 'type' => 'number', 'label' => 'N']])['columns'][0]['decimals']);
	}

	public function testSkipsDisplayFieldsAndUnnamedLabelsFallBackToTheId(): void {
		$schema = TablesSchema::forFields([
			['id' => 'f_head', 'type' => 'heading', 'label' => 'Intro'],
			['id' => 'f_name', 'type' => 'text', 'label' => '  '],
			['id' => 'f_bad'],
		]);
		$this->assertSame(['f_name', '@submitted'], $schema['sources']);
		$this->assertSame('f_name', $schema['columns'][0]['title']);
	}
}
