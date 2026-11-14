<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Tests\Unit\Forms;

use OCA\AdvancedForms\Forms\SubmissionValidator;
use PHPUnit\Framework\TestCase;

class SubmissionValidatorTest extends TestCase {
	/**
	 * @param array<string, mixed> $payload
	 * @return array{data: array<string, mixed>, errors: list<array{field: string, code: string}>}
	 */
	private function run(array $payload): array {
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
		$result = $this->run(['f_name' => '  Ada  ', 'f_email' => 'ada@example.com', 'f_kind' => 'new']);
		$this->assertSame([], $result['errors']);
		$this->assertSame(['f_name' => 'Ada', 'f_email' => 'ada@example.com', 'f_kind' => 'new'], $result['data']);
	}

	public function testRequiredFieldsMissing(): void {
		$this->assertSame(
			['f_email:required', 'f_kind:required', 'f_name:required'],
			$this->errorKeys($this->run([])),
		);
	}

	public function testWhitespaceOnlyCountsAsMissing(): void {
		$result = $this->run(['f_name' => "   \u{00A0}", 'f_email' => 'a@example.com', 'f_kind' => 'new']);
		$this->assertSame(['f_name:required'], $this->errorKeys($result));
	}

	public function testUnknownFieldRejected(): void {
		$result = $this->run(['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new', 'f_evil' => 'x']);
		$this->assertSame(['f_evil:unknown_field'], $this->errorKeys($result));
	}

	public function testDisplayFieldCannotCarryAValue(): void {
		$result = $this->run(['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new', 'f_note' => 'x']);
		$this->assertSame(['f_note:unknown_field'], $this->errorKeys($result));
	}

	public function testHiddenFieldValueIsDropped(): void {
		// f_url is shown only when f_kind is "support".
		$result = $this->run(['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new', 'f_url' => 'https://x.test']);
		$this->assertSame([], $result['errors']);
		$this->assertSame(['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new'], $result['data']);
	}

	public function testVisibleFieldValueIsKept(): void {
		$result = $this->run(['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'support', 'f_url' => 'https://x.test']);
		$this->assertSame([], $result['errors']);
		$this->assertSame('https://x.test', $result['data']['f_url']);
	}

	public function testInvalidValuesReportCodes(): void {
		$result = $this->run(['f_name' => 'Ada', 'f_email' => 'not-an-email', 'f_kind' => 'bogus', 'f_phone' => '12']);
		$this->assertSame(
			['f_email:invalid_email', 'f_kind:invalid_option', 'f_phone:invalid_phone'],
			$this->errorKeys($result),
		);
	}

	public function testWrongTypesAreErrorsNotSilentlyEmpty(): void {
		$result = $this->run(['f_name' => ['x'], 'f_email' => 'a@example.com', 'f_kind' => 5]);
		$this->assertSame(['f_kind:invalid_type', 'f_name:invalid_type'], $this->errorKeys($result));
	}

	public function testValidPhoneIsStoredAsTyped(): void {
		$result = $this->run(['f_name' => 'Ada', 'f_email' => 'a@example.com', 'f_kind' => 'new', 'f_phone' => ' +1 (860) 555-0100 ']);
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
		$result = $this->run(['f_name' => str_repeat('a', 2001), 'f_email' => 'a@example.com', 'f_kind' => 'new']);
		$this->assertSame(['f_name:too_long'], $this->errorKeys($result));
	}

	public function testInvalidUtf8Rejected(): void {
		$result = $this->run(['f_name' => "bad\xC3\x28", 'f_email' => 'a@example.com', 'f_kind' => 'new']);
		$this->assertSame(['f_name:invalid_encoding'], $this->errorKeys($result));
	}
}
