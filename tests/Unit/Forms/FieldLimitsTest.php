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
	 * @return array<string, array{array<string, mixed>, mixed, mixed, string|null}>
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
			'date and time' => [['type' => 'datetime'], '2026-05-01T09:30', '2026-05-01T09:30', null],
			'date and time needs both' => [['type' => 'datetime'], '2026-05-01', null, 'invalid_date'],
			'date and time on a day that does not exist' => [['type' => 'datetime'], '2026-02-30T09:30', null, 'invalid_date'],
			'date and time too early' => [['type' => 'datetime', 'min' => '2026-05-01T09:00'], '2026-05-01T08:59', null, 'date_too_early'],
			'date and time too late' => [['type' => 'datetime', 'max' => '2026-05-01T17:00'], '2026-05-02T00:00', null, 'date_too_late'],
			'rating as text' => [['type' => 'rating'], '4', 4, null],
			'rating has five stars unless told' => [['type' => 'rating'], 6, null, 'above_max'],
			'rating with ten stars' => [['type' => 'rating', 'max' => 10], 10, 10, null],
			'rating starts at one' => [['type' => 'rating'], 0, null, 'below_min'],
			'rating is whole' => [['type' => 'rating'], '3.5', null, 'not_step'],
			'rating unanswered' => [['type' => 'rating'], '', null, null],
			'scale from zero' => [['type' => 'scale', 'min' => 0, 'max' => 10], 0, 0, null],
			'scale from one' => [['type' => 'scale', 'max' => 10], 0, null, 'below_min'],
			'scale past the end' => [['type' => 'scale', 'min' => 0, 'max' => 10], 11, null, 'above_max'],
			'consent ticked' => [['type' => 'consent'], true, true, null],
			'consent is ticked or not' => [['type' => 'consent'], 'yes', null, 'invalid_type'],
		];
	}

	/**
	 * @param array<string, mixed> $field
	 */
	#[DataProvider('cases')]
	public function testClean(array $field, mixed $raw, mixed $value, ?string $error): void {
		$this->assertSame([$value, $error], FieldValueValidator::clean($field + ['label' => 'X'], $raw));
	}
}
