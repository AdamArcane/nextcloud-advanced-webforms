<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Rules;

use OCA\Webforms\Rules\RuleCycleException;
use OCA\Webforms\Rules\RuleEngine;
use OCA\Webforms\Rules\RuleValidator;
use PHPUnit\Framework\TestCase;

class RuleValidatorTest extends TestCase {
	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function fields(): array {
		return [
			'f_kind' => ['type' => 'select', 'label' => 'Kind', 'options' => [['value' => 'new', 'label' => 'New'], ['value' => 'support', 'label' => 'Support']]],
			'f_tags' => ['type' => 'checkboxes', 'label' => 'Tags', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
			'f_count' => ['type' => 'number', 'label' => 'Count'],
			'f_agree' => ['type' => 'checkbox', 'label' => 'Agree'],
			'f_when' => ['type' => 'date', 'label' => 'When'],
			'f_name' => ['type' => 'text', 'label' => 'Name'],
			'f_url' => ['type' => 'text', 'label' => 'URL'],
			'f_note' => ['type' => 'paragraph', 'text' => 'Note'],
		];
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function rule(array $overrides = []): array {
		return array_merge([
			'id' => 'rule_1',
			'effect' => 'show',
			'targets' => ['f_url'],
			'when' => ['all' => [['field' => 'f_kind', 'op' => 'eq', 'value' => 'support']]],
		], $overrides);
	}

	/**
	 * @param list<array<string, mixed>> $rules
	 * @return list<string> error codes
	 */
	private function codes(array $rules): array {
		$errors = (new RuleValidator())->validate(['fields' => self::fields(), 'rules' => $rules]);
		return array_map(static fn (array $e): string => $e['code'], $errors);
	}

	public function testValidRulePasses(): void {
		$this->assertSame([], $this->codes([self::rule()]));
	}

	public function testNoRulesIsValid(): void {
		$this->assertSame([], $this->codes([]));
	}

	public function testSpecExampleStaysValid(): void {
		$spec = file_get_contents(__DIR__ . '/../../../docs/SCHEMA.md');
		$this->assertIsString($spec);
		$this->assertSame(1, preg_match('/```json\n(.*?)```/s', $spec, $m));
		$definition = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame([], (new RuleValidator())->validate($definition));
	}

	public function testDuplicateRuleId(): void {
		$this->assertSame(['rule_duplicate_id'], $this->codes([self::rule(), self::rule()]));
	}

	public function testBadRuleId(): void {
		$this->assertContains('rule_bad_id', $this->codes([self::rule(['id' => 'R'])]));
	}

	public function testBadEffect(): void {
		$this->assertContains('rule_bad_effect', $this->codes([self::rule(['effect' => 'explode'])]));
	}

	public function testNoTargets(): void {
		$this->assertContains('rule_no_targets', $this->codes([self::rule(['targets' => []])]));
	}

	public function testUnknownTarget(): void {
		$this->assertContains('rule_unknown_target', $this->codes([self::rule(['targets' => ['f_missing']])]));
	}

	public function testRequireOnDisplayFieldIsRejected(): void {
		$this->assertContains('rule_require_on_display_field', $this->codes([self::rule(['effect' => 'require', 'targets' => ['f_note']])]));
	}

	public function testShowOnDisplayFieldIsAllowed(): void {
		$this->assertSame([], $this->codes([self::rule(['targets' => ['f_note']])]));
	}

	public function testUnknownSourceField(): void {
		$when = ['all' => [['field' => 'f_nope', 'op' => 'eq', 'value' => 'x']]];
		$this->assertContains('cond_unknown_field', $this->codes([self::rule(['when' => $when])]));
	}

	public function testDisplayFieldCannotBeASource(): void {
		$when = ['all' => [['field' => 'f_note', 'op' => 'not_empty']]];
		$this->assertContains('cond_source_not_input', $this->codes([self::rule(['when' => $when])]));
	}

	public function testOperatorNotAllowedForType(): void {
		$when = ['all' => [['field' => 'f_kind', 'op' => 'gt', 'value' => 'new']]];
		$this->assertContains('cond_bad_operator', $this->codes([self::rule(['when' => $when])]));
	}

	public function testSelectValueMustBeAnOption(): void {
		$when = ['all' => [['field' => 'f_kind', 'op' => 'eq', 'value' => 'other']]];
		$this->assertContains('cond_bad_value', $this->codes([self::rule(['when' => $when])]));
	}

	public function testCheckboxesAnyAllNoneTakeAListOfOptions(): void {
		foreach (['contains_any', 'contains_all', 'contains_none'] as $op) {
			$ok = ['all' => [['field' => 'f_tags', 'op' => $op, 'value' => ['a', 'b']]]];
			$this->assertSame([], $this->codes([self::rule(['when' => $ok])]), $op);
			foreach ([[], 'a', ['a', 'z']] as $bad) {
				$when = ['all' => [['field' => 'f_tags', 'op' => $op, 'value' => $bad]]];
				$this->assertContains('cond_bad_value', $this->codes([self::rule(['when' => $when])]), $op);
			}
		}
		// Only checkboxes have these operators.
		$when = ['all' => [['field' => 'f_kind', 'op' => 'contains_any', 'value' => ['new']]]];
		$this->assertContains('cond_bad_operator', $this->codes([self::rule(['when' => $when])]));
	}

	public function testCheckboxesValueMustBeAnOption(): void {
		$ok = ['all' => [['field' => 'f_tags', 'op' => 'contains', 'value' => 'a']]];
		$bad = ['all' => [['field' => 'f_tags', 'op' => 'contains', 'value' => 'z']]];
		$this->assertSame([], $this->codes([self::rule(['when' => $ok])]));
		$this->assertContains('cond_bad_value', $this->codes([self::rule(['when' => $bad])]));
	}

	public function testNumberValueMustBeANumber(): void {
		$bad = ['all' => [['field' => 'f_count', 'op' => 'gt', 'value' => '10']]];
		$ok = ['all' => [['field' => 'f_count', 'op' => 'gt', 'value' => 10]]];
		$this->assertContains('cond_bad_value', $this->codes([self::rule(['when' => $bad])]));
		$this->assertSame([], $this->codes([self::rule(['when' => $ok])]));
	}

	public function testCheckboxValueMustBeBoolean(): void {
		$bad = ['all' => [['field' => 'f_agree', 'op' => 'eq', 'value' => 'yes']]];
		$this->assertContains('cond_bad_value', $this->codes([self::rule(['when' => $bad])]));
	}

	public function testDateValueMustBeValid(): void {
		$bad = ['all' => [['field' => 'f_when', 'op' => 'gt', 'value' => 'soon']]];
		$ok = ['all' => [['field' => 'f_when', 'op' => 'gt', 'value' => '2026-01-01']]];
		$this->assertContains('cond_bad_value', $this->codes([self::rule(['when' => $bad])]));
		$this->assertSame([], $this->codes([self::rule(['when' => $ok])]));
	}

	public function testEmptyOperatorTakesNoValue(): void {
		$bad = ['all' => [['field' => 'f_name', 'op' => 'empty', 'value' => 'x']]];
		$ok = ['all' => [['field' => 'f_name', 'op' => 'empty']]];
		$this->assertContains('cond_bad_value', $this->codes([self::rule(['when' => $bad])]));
		$this->assertSame([], $this->codes([self::rule(['when' => $ok])]));
	}

	public function testValueOperatorsRequireAValue(): void {
		$when = ['all' => [['field' => 'f_name', 'op' => 'eq']]];
		$this->assertContains('cond_bad_value', $this->codes([self::rule(['when' => $when])]));
	}

	public function testInNeedsANonEmptyListOfValidValues(): void {
		$empty = ['all' => [['field' => 'f_kind', 'op' => 'in', 'value' => []]]];
		$badItem = ['all' => [['field' => 'f_kind', 'op' => 'in', 'value' => ['new', 'other']]]];
		$ok = ['all' => [['field' => 'f_kind', 'op' => 'in', 'value' => ['new', 'support']]]];
		$this->assertContains('cond_bad_value', $this->codes([self::rule(['when' => $empty])]));
		$this->assertContains('cond_bad_value', $this->codes([self::rule(['when' => $badItem])]));
		$this->assertSame([], $this->codes([self::rule(['when' => $ok])]));
	}

	public function testEmptyGroupIsRejected(): void {
		$this->assertContains('cond_bad_shape', $this->codes([self::rule(['when' => ['all' => []]])]));
		$this->assertContains('cond_bad_shape', $this->codes([self::rule(['when' => ['any' => []]])]));
	}

	public function testGroupWithBothAllAndAnyIsRejected(): void {
		$leaf = ['field' => 'f_name', 'op' => 'not_empty'];
		$this->assertContains('cond_bad_shape', $this->codes([self::rule(['when' => ['all' => [$leaf], 'any' => [$leaf]]])]));
	}

	public function testMissingWhenIsRejected(): void {
		$rule = self::rule();
		unset($rule['when']);
		$this->assertContains('cond_bad_shape', $this->codes([$rule]));
	}

	public function testNestingDepthLimit(): void {
		$leaf = ['field' => 'f_name', 'op' => 'not_empty'];
		$wrap = static fn (array $inner): array => ['all' => [$inner]];

		// Five nested groups is the maximum.
		$five = $wrap($wrap($wrap($wrap($wrap($leaf)))));
		$six = $wrap($five);

		$this->assertSame([], $this->codes([self::rule(['when' => $five])]));
		$this->assertContains('cond_too_deep', $this->codes([self::rule(['when' => $six])]));
	}

	public function testSelfDependencyIsACycle(): void {
		$when = ['all' => [['field' => 'f_name', 'op' => 'not_empty']]];
		$this->assertContains('rules_cycle', $this->codes([self::rule(['targets' => ['f_name'], 'when' => $when])]));
	}

	public function testTwoRuleCycle(): void {
		$a = self::rule([
			'id' => 'rule_a',
			'targets' => ['f_name'],
			'when' => ['all' => [['field' => 'f_url', 'op' => 'not_empty']]],
		]);
		$b = self::rule([
			'id' => 'rule_b',
			'targets' => ['f_url'],
			'when' => ['all' => [['field' => 'f_name', 'op' => 'not_empty']]],
		]);
		$this->assertContains('rules_cycle', $this->codes([$a, $b]));
	}

	public function testChainWithoutLoopIsValid(): void {
		$a = self::rule([
			'id' => 'rule_a',
			'targets' => ['f_name'],
			'when' => ['all' => [['field' => 'f_kind', 'op' => 'eq', 'value' => 'new']]],
		]);
		$b = self::rule([
			'id' => 'rule_b',
			'targets' => ['f_url'],
			'when' => ['all' => [['field' => 'f_name', 'op' => 'not_empty']]],
		]);
		$this->assertSame([], $this->codes([$a, $b]));
	}

	public function testEngineRefusesCyclicDefinitions(): void {
		$when = ['all' => [['field' => 'f_name', 'op' => 'not_empty']]];
		$definition = ['fields' => self::fields(), 'rules' => [self::rule(['targets' => ['f_name'], 'when' => $when])]];
		$this->expectException(RuleCycleException::class);
		(new RuleEngine())->evaluate($definition, []);
	}

	public function testErrorsCarryAPath(): void {
		$errors = (new RuleValidator())->validate([
			'fields' => self::fields(),
			'rules' => [self::rule(), self::rule(['id' => 'rule_2', 'targets' => ['f_missing']])],
		]);
		$this->assertSame([['code' => 'rule_unknown_target', 'path' => 'rules[1].targets[0]']], $errors);
	}
}
