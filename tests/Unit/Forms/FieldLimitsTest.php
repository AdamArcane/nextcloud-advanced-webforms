<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\FieldValueValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FieldLimitsTest extends TestCase {
	/**
	 * @return array<string, array{array<string, mixed>, mixed, string|null, string|null}>
	 */
	public static function cases(): array {
		return [
			'text long enough' => [['type' => 'text', 'minLength' => 3], 'abc', 'abc', null],
			'text too short' => [['type' => 'text', 'minLength' => 3], 'ab', null, 'too_short'],
			'textarea too short' => [['type' => 'textarea', 'minLength' => 10], 'short', null, 'too_short'],
			'blank is empty not short' => [['type' => 'text', 'minLength' => 3], '  ', null, null],
			'date on the earliest day' => [['type' => 'date', 'min' => '2026-05-01'], '2026-05-01', '2026-05-01', null],
			'date too early' => [['type' => 'date', 'min' => '2026-05-01'], '2026-04-30', null, 'date_too_early'],
			'date too late' => [['type' => 'date', 'max' => '2026-05-31'], '2026-06-01', null, 'date_too_late'],
			'date without limits' => [['type' => 'date'], '1999-01-01', '1999-01-01', null],
			'time in range' => [['type' => 'time', 'min' => '09:00', 'max' => '17:00'], '12:30', '12:30', null],
			'time too early' => [['type' => 'time', 'min' => '09:00'], '08:59', null, 'time_too_early'],
			'time too late' => [['type' => 'time', 'max' => '17:00'], '17:01', null, 'time_too_late'],
		];
	}

	/**
	 * @param array<string, mixed> $field
	 */
	#[DataProvider('cases')]
	public function testClean(array $field, mixed $raw, ?string $value, ?string $error): void {
		$this->assertSame([$value, $error], FieldValueValidator::clean($field + ['label' => 'X'], $raw));
	}
}
