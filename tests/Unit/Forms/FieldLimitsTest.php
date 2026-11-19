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
			'slider without settings is 0 to 100' => [['type' => 'slider'], 40, 40, null],
			'slider past 100' => [['type' => 'slider'], 101, null, 'above_max'],
			'slider by step' => [['type' => 'slider', 'min' => 10, 'max' => 50, 'step' => 10], 25, null, 'not_step'],
			'slider on a step' => [['type' => 'slider', 'min' => 10, 'max' => 50, 'step' => 10], '30', 30, null],
			'slider unanswered' => [['type' => 'slider'], null, null, null],
			'color' => [['type' => 'color'], '#FF8800', '#ff8800', null],
			'color needs six digits' => [['type' => 'color'], '#f80', null, 'invalid_color'],
			'color is not a word' => [['type' => 'color'], 'red', null, 'invalid_color'],
			'date range' => [['type' => 'daterange'], ['start' => '2026-05-01', 'end' => '2026-05-03'], ['start' => '2026-05-01', 'end' => '2026-05-03'], null],
			'date range of one day' => [['type' => 'daterange'], ['start' => '2026-05-01', 'end' => '2026-05-01'], ['start' => '2026-05-01', 'end' => '2026-05-01'], null],
			'date range backwards' => [['type' => 'daterange'], ['start' => '2026-05-03', 'end' => '2026-05-01'], null, 'range_reversed'],
			'date range with half missing' => [['type' => 'daterange'], ['start' => '2026-05-03', 'end' => ''], null, 'incomplete_range'],
			'date range empty' => [['type' => 'daterange'], ['start' => '', 'end' => ''], null, null],
			'date range too early' => [['type' => 'daterange', 'min' => '2026-05-02'], ['start' => '2026-05-01', 'end' => '2026-05-03'], null, 'date_too_early'],
			'date range too late' => [['type' => 'daterange', 'max' => '2026-05-02'], ['start' => '2026-05-01', 'end' => '2026-05-03'], null, 'date_too_late'],
			'date range must end after it starts' => [['type' => 'daterange', 'minDays' => 1], ['start' => '2026-05-01', 'end' => '2026-05-01'], null, 'range_too_short'],
			'date range ending the next day' => [['type' => 'daterange', 'minDays' => 1], ['start' => '2026-05-01', 'end' => '2026-05-02'], ['start' => '2026-05-01', 'end' => '2026-05-02'], null],
			'date range too long' => [['type' => 'daterange', 'maxDays' => 7], ['start' => '2026-05-01', 'end' => '2026-05-09'], null, 'range_too_long'],
			'date range at the longest' => [['type' => 'daterange', 'maxDays' => 7], ['start' => '2026-05-01', 'end' => '2026-05-08'], ['start' => '2026-05-01', 'end' => '2026-05-08'], null],
			'date range counts across a clock change' => [['type' => 'daterange', 'maxDays' => 1], ['start' => '2026-03-28', 'end' => '2026-03-29'], ['start' => '2026-03-28', 'end' => '2026-03-29'], null],
			'name by default has first and last' => [['type' => 'name'], ['first' => ' Ada ', 'last' => 'Lovelace'], ['first' => 'Ada', 'last' => 'Lovelace'], null],
			'name part that is not asked for' => [['type' => 'name'], ['first' => 'Ada', 'middle' => 'K'], null, 'invalid_type'],
			'name with a middle part' => [['type' => 'name', 'parts' => ['first', 'middle', 'last']], ['last' => 'L', 'middle' => 'K'], ['middle' => 'K', 'last' => 'L'], null],
			'name all blank' => [['type' => 'name'], ['first' => ' ', 'last' => ''], null, null],
			'name part too long' => [['type' => 'name'], ['first' => str_repeat('a', 201)], null, 'too_long'],
			'name as text' => [['type' => 'name'], 'Ada', null, 'invalid_type'],
			'address' => [['type' => 'address'], ['city' => 'Oslo', 'street' => 'Main 1', 'country' => 'Norway'], ['street' => 'Main 1', 'city' => 'Oslo', 'country' => 'Norway'], null],
			'address part not offered' => [['type' => 'address', 'parts' => ['street', 'city']], ['postal' => '1'], null, 'invalid_type'],
			'matrix one per row' => [['type' => 'matrix', 'questions' => [['value' => 'food', 'label' => 'Food'], ['value' => 'staff', 'label' => 'Staff']], 'options' => [['value' => 'good', 'label' => 'Good'], ['value' => 'bad', 'label' => 'Bad']]], ['staff' => 'bad', 'food' => 'good'], ['food' => 'good', 'staff' => 'bad'], null],
			'matrix unknown row' => [['type' => 'matrix', 'questions' => [['value' => 'food', 'label' => 'Food']], 'options' => [['value' => 'good', 'label' => 'Good']]], ['drink' => 'good'], null, 'invalid_option'],
			'matrix unknown column' => [['type' => 'matrix', 'questions' => [['value' => 'food', 'label' => 'Food']], 'options' => [['value' => 'good', 'label' => 'Good']]], ['food' => 'meh'], null, 'invalid_option'],
			'matrix with several per row' => [['type' => 'matrix', 'multiple' => true, 'questions' => [['value' => 'food', 'label' => 'Food']], 'options' => [['value' => 'good', 'label' => 'Good'], ['value' => 'bad', 'label' => 'Bad']]], ['food' => ['bad', 'good']], ['food' => ['good', 'bad']], null],
			'matrix a list where one is wanted' => [['type' => 'matrix', 'questions' => [['value' => 'food', 'label' => 'Food']], 'options' => [['value' => 'good', 'label' => 'Good']]], ['food' => ['good']], null, 'invalid_type'],
			'matrix nothing picked' => [['type' => 'matrix', 'questions' => [['value' => 'food', 'label' => 'Food']], 'options' => [['value' => 'good', 'label' => 'Good']]], ['food' => ''], null, null],
			'signature' => [['type' => 'signature'], 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', null],
			'signature is a picture' => [['type' => 'signature'], 'data:text/html;base64,PGI+', null, 'invalid_signature'],
			'signature is a real png' => [['type' => 'signature'], 'data:image/png;base64,PGI+', null, 'invalid_signature'],
			'signature unanswered' => [['type' => 'signature'], '', null, null],
			'signature too large' => [['type' => 'signature'], 'data:image/png;base64,' . str_repeat('A', 200000), null, 'signature_too_large'],
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
