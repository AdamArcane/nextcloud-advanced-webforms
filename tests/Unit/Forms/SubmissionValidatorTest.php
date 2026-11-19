<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\SubmissionValidator;
use PHPUnit\Framework\TestCase;

class SubmissionValidatorTest extends TestCase {
	/**
	 * @param array<string, mixed> $payload
	 * @return array{data: array<string, mixed>, errors: list<array{field: string, code: string}>}
	 */
	private function validate(array $payload): array {
		return (new SubmissionValidator())->validate(DefinitionValidatorTest::specExample(), $payload);
	}

	/**
	 * @param array{data: array<string, mixed>, errors: list<array{field: string, code: string}>} $result
	 * @return list<string> "field:code"
	 */
	private function errorKeys(array $result): array {
		$keys = array_map(static fn (array $e): string => $e['field'] . ':' . $e['code'], $result['errors']);
		sort($keys);
		return $keys;
	}

	public function testValidSubmissionIsCleaned(): void {
		$result = $this->validate(['f_name' => '  Ada  ', 'f_email' => 'ada@example.com', 'f_kind' => 'new']);
		$this->assertSame([], $result['errors']);
		$this->assertSame(['f_name' => 'Ada', 'f_email' => 'ada@example.com', 'f_kind' => 'new'], $result['data']);
	}

	public function testRequiredFieldsMissing(): void {
		$this->assertSame(
			['f_email:required', 'f_kind:required', 'f_name:required'],
			$this->errorKeys($this->validate([])),
		);
	}

	public function testWhitespaceOnlyCountsAsMissing(): void {
		$result = $this->validate(['f_name' => "   \u{00A0}", 'f_email' => 'a@example.com', 'f_kind' => 'new']);
		$this->assertSame(['f_name:required'], $this->errorKeys($result));
	}

	public function testUnknownFieldRejected(): void {
		$result = $this->validate(['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new', 'f_evil' => 'x']);
		$this->assertSame(['f_evil:unknown_field'], $this->errorKeys($result));
	}

	public function testDisplayFieldCannotCarryAValue(): void {
		$result = $this->validate(['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new', 'f_note' => 'x']);
		$this->assertSame(['f_note:unknown_field'], $this->errorKeys($result));
	}

	public function testHiddenFieldValueIsDropped(): void {
		// f_url is shown only when f_kind is "support".
		$result = $this->validate(['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new', 'f_url' => 'https://x.test']);
		$this->assertSame([], $result['errors']);
		$this->assertSame(['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new'], $result['data']);
	}

	public function testVisibleFieldValueIsKept(): void {
		$result = $this->validate(['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'support', 'f_url' => 'https://x.test']);
		$this->assertSame([], $result['errors']);
		$this->assertSame('https://x.test', $result['data']['f_url']);
	}

	public function testInvalidValuesReportCodes(): void {
		$result = $this->validate(['f_name' => 'Ada', 'f_email' => 'not-an-email', 'f_kind' => 'bogus', 'f_phone' => '12']);
		$this->assertSame(
			['f_email:invalid_email', 'f_kind:invalid_option', 'f_phone:invalid_phone'],
			$this->errorKeys($result),
		);
	}

	public function testWrongTypesAreErrorsNotSilentlyEmpty(): void {
		$result = $this->validate(['f_name' => ['x'], 'f_email' => 'a@example.com', 'f_kind' => 5]);
		$this->assertSame(['f_kind:invalid_type', 'f_name:invalid_type'], $this->errorKeys($result));
	}

	public function testValidPhoneIsStoredAsTyped(): void {
		$result = $this->validate(['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new', 'f_phone' => ' +1 (860) 555-0100 ']);
		$this->assertSame('+1 (860) 555-0100', $result['data']['f_phone']);
	}

	public function testRequireRuleMakesFieldRequired(): void {
		$definition = DefinitionValidatorTest::specExample();
		$definition['rules'][] = [
			'id' => 'rule_2',
			'effect' => 'require',
			'targets' => ['f_phone'],
			'when' => ['all' => [['field' => 'f_kind', 'op' => 'eq', 'value' => 'new']]],
		];
		$result = (new SubmissionValidator())->validate($definition, ['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new']);
		$this->assertSame(['f_phone:required'], $this->errorKeys($result));
	}

	public function testRequiredCheckboxMustBeTicked(): void {
		$definition = DefinitionValidatorTest::specExample();
		$definition['fields']['f_agree'] = ['type' => 'checkbox', 'label' => 'Agree', 'required' => true];
		$validator = new SubmissionValidator();
		$base = ['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new'];

		$this->assertSame(['f_agree:required'], $this->errorKeys($validator->validate($definition, $base + ['f_agree' => false])));
		$this->assertSame(['f_agree:required'], $this->errorKeys($validator->validate($definition, $base)));
		$this->assertSame([], $validator->validate($definition, $base + ['f_agree' => true])['errors']);
	}

	public function testNumberLimitsAndWholeNumbersStoredAsInts(): void {
		$definition = DefinitionValidatorTest::specExample();
		$definition['fields']['f_qty'] = ['type' => 'number', 'label' => 'Qty', 'min' => 1, 'max' => 10, 'step' => 1];
		$validator = new SubmissionValidator();
		$base = ['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new'];

		$this->assertSame(['f_qty:above_max'], $this->errorKeys($validator->validate($definition, $base + ['f_qty' => 11])));
		$this->assertSame(['f_qty:below_min'], $this->errorKeys($validator->validate($definition, $base + ['f_qty' => '0'])));
		$this->assertSame(['f_qty:not_step'], $this->errorKeys($validator->validate($definition, $base + ['f_qty' => 2.5])));
		$this->assertSame(['f_qty:invalid_number'], $this->errorKeys($validator->validate($definition, $base + ['f_qty' => '1e3'])));
		$this->assertSame(4, $validator->validate($definition, $base + ['f_qty' => '4'])['data']['f_qty']);
	}

	public function testCheckboxesAreStoredInOptionOrder(): void {
		$definition = DefinitionValidatorTest::specExample();
		$definition['fields']['f_tags'] = ['type' => 'checkboxes', 'label' => 'Tags', 'maxSelected' => 2, 'options' => [
			['value' => 'aaa', 'label' => 'A'], ['value' => 'bbb', 'label' => 'B'], ['value' => 'ccc', 'label' => 'C'],
		]];
		$validator = new SubmissionValidator();
		$base = ['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new'];

		$this->assertSame(['ccc', 'aaa'], array_reverse($validator->validate($definition, $base + ['f_tags' => ['ccc', 'aaa']])['data']['f_tags']));
		$this->assertSame(['f_tags:too_many'], $this->errorKeys($validator->validate($definition, $base + ['f_tags' => ['aaa', 'bbb', 'ccc']])));
		$this->assertSame(['f_tags:duplicate_option'], $this->errorKeys($validator->validate($definition, $base + ['f_tags' => ['aaa', 'aaa']])));
		$this->assertSame(['f_tags:invalid_option'], $this->errorKeys($validator->validate($definition, $base + ['f_tags' => ['zzz']])));
	}

	public function testDatesAreCalendarChecked(): void {
		$definition = DefinitionValidatorTest::specExample();
		$definition['fields']['f_when'] = ['type' => 'date', 'label' => 'When'];
		$validator = new SubmissionValidator();
		$base = ['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new'];

		$this->assertSame(['f_when:invalid_date'], $this->errorKeys($validator->validate($definition, $base + ['f_when' => '2026-02-30'])));
		$this->assertSame([], $validator->validate($definition, $base + ['f_when' => '2028-02-29'])['errors']);
	}

	public function testOverlongTextRejected(): void {
		$result = $this->validate(['f_name' => str_repeat('a', 2001), 'f_email' => 'a@example.com', 'f_kind' => 'new']);
		$this->assertSame(['f_name:too_long'], $this->errorKeys($result));
	}

	public function testInvalidUtf8Rejected(): void {
		$result = $this->validate(['f_name' => "bad\xC3\x28", 'f_email' => 'a@example.com', 'f_kind' => 'new']);
		$this->assertSame(['f_name:invalid_encoding'], $this->errorKeys($result));
	}

	/**
	 * @param array<string, mixed> $extra
	 * @param array<string, mixed> $payload
	 * @return array{data: array<string, mixed>, errors: list<array{field: string, code: string}>}
	 */
	private function validateUpload(array $extra, array $payload): array {
		$definition = DefinitionValidatorTest::specExample();
		$definition['fields']['f_up'] = array_merge(['type' => 'file', 'label' => 'CV'], $extra);
		$definition['pages'][0]['rows'][] = ['id' => 'r_up', 'cells' => [['id' => 'c_up', 'span' => 12, 'field' => 'f_up']]];
		$base = ['f_name' => 'Ada', 'f_email' => 'ada@example.com', 'f_kind' => 'new'];
		return (new SubmissionValidator())->validate($definition, array_merge($base, $payload));
	}

	public function testUploadFieldTakesUploadIds(): void {
		$id = str_repeat('a1', 16);
		$result = $this->validateUpload(['maxFiles' => 2], ['f_up' => [$id]]);
		$this->assertSame([], $result['errors']);
		$this->assertSame([$id], $result['data']['f_up']);
	}

	public function testUploadFieldErrors(): void {
		$a = str_repeat('a1', 16);
		$b = str_repeat('b2', 16);
		$this->assertSame(['f_up:too_many_files'], $this->errorKeys($this->validateUpload([], ['f_up' => [$a, $b]])));
		$this->assertSame(['f_up:duplicate_file'], $this->errorKeys($this->validateUpload(['maxFiles' => 2], ['f_up' => [$a, $a]])));
		$this->assertSame(['f_up:invalid_type'], $this->errorKeys($this->validateUpload([], ['f_up' => ['short']])));
		$this->assertSame(['f_up:invalid_type'], $this->errorKeys($this->validateUpload([], ['f_up' => 'x'])));
		$this->assertSame(['f_up:required'], $this->errorKeys($this->validateUpload(['required' => true], ['f_up' => []])));
	}

	/**
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $payload
	 * @return list<string>
	 */
	private function partsErrors(array $fields, array $payload): array {
		$definition = ['fields' => $fields, 'rules' => []];
		return $this->errorKeys((new SubmissionValidator())->validate($definition, $payload));
	}

	public function testRequiredNameNeedsFirstAndLast(): void {
		$fields = ['f_who' => ['type' => 'name', 'label' => 'Name', 'required' => true]];
		$this->assertSame(['f_who:incomplete'], $this->partsErrors($fields, ['f_who' => ['first' => 'Ada']]));
		$this->assertSame(['f_who:required'], $this->partsErrors($fields, ['f_who' => ['first' => '', 'last' => '']]));
		$this->assertSame([], $this->partsErrors($fields, ['f_who' => ['first' => 'Ada', 'last' => 'Lovelace']]));
	}

	public function testOptionalNameMayBeHalfFilled(): void {
		$fields = ['f_who' => ['type' => 'name', 'label' => 'Name']];
		$this->assertSame([], $this->partsErrors($fields, ['f_who' => ['first' => 'Ada']]));
	}

	public function testRequiredAddressAsksOnlyForThePartsItShows(): void {
		$fields = ['f_where' => ['type' => 'address', 'label' => 'Where', 'required' => true, 'parts' => ['street', 'city']]];
		$this->assertSame([], $this->partsErrors($fields, ['f_where' => ['street' => 'Main 1', 'city' => 'Oslo']]));
		$this->assertSame(['f_where:incomplete'], $this->partsErrors($fields, ['f_where' => ['city' => 'Oslo']]));
	}

	public function testRequiredMatrixNeedsEveryRow(): void {
		$fields = ['f_grid' => ['type' => 'matrix', 'label' => 'Grid', 'required' => true,
			'questions' => [['value' => 'food', 'label' => 'Food'], ['value' => 'staff', 'label' => 'Staff']],
			'options' => [['value' => 'good', 'label' => 'Good'], ['value' => 'bad', 'label' => 'Bad']]]];
		$this->assertSame(['f_grid:incomplete'], $this->partsErrors($fields, ['f_grid' => ['food' => 'good']]));
		$this->assertSame([], $this->partsErrors($fields, ['f_grid' => ['food' => 'good', 'staff' => 'bad']]));
	}

	public function testRequiredSignatureAndColourNeedAnAnswer(): void {
		$fields = ['f_sign' => ['type' => 'signature', 'label' => 'Sign', 'required' => true], 'f_col' => ['type' => 'color', 'label' => 'Colour', 'required' => true]];
		$this->assertSame(['f_col:required', 'f_sign:required'], $this->partsErrors($fields, ['f_sign' => '', 'f_col' => '']));
	}
}
