<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Tests\Unit\Rules;

use OCA\AdvancedForms\Rules\RuleEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the shared vectors (tests/vectors/rules.json). The TypeScript evaluator
 * runs the same file, which keeps the two implementations in agreement.
 */
class VectorsTest extends TestCase {
	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function vectorProvider(): iterable {
		$json = file_get_contents(__DIR__ . '/../../vectors/rules.json');
		self::assertIsString($json);
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		foreach ($data['cases'] as $case) {
			yield $case['name'] => [$case];
		}
	}

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('vectorProvider')]
	public function testVector(array $case): void {
		$result = (new RuleEngine())->evaluate(
			['fields' => $case['fields'], 'rules' => $case['rules']],
			$case['values'],
		);

		$visible = $result['visible'];
		$required = $result['required'];
		sort($visible, SORT_STRING);
		sort($required, SORT_STRING);

		$this->assertSame($case['expect']['visible'], $visible, 'visible fields');
		$this->assertSame($case['expect']['required'], $required, 'required fields');
	}

	public function testVectorNamesAreUnique(): void {
		$names = array_keys(iterator_to_array(self::vectorProvider()));
		$this->assertGreaterThan(50, count($names));
	}
}
