<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\EntryFilter;
use OCA\Webforms\Rules\RuleEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryFilterTest extends TestCase {
	private const FIELDS = [
		'f_name' => ['type' => 'text', 'label' => 'Name'],
		'f_kind' => ['type' => 'select', 'label' => 'Kind', 'options' => [['value' => 'opt_sup', 'label' => 'Support'], ['value' => 'opt_sal', 'label' => 'Sales']]],
		'f_tags' => ['type' => 'checkboxes', 'label' => 'Tags', 'options' => [['value' => 't1', 'label' => 'Urgent'], ['value' => 't2', 'label' => 'VIP']]],
		'f_size' => ['type' => 'number', 'label' => 'Size'],
		'f_cv' => ['type' => 'file', 'label' => 'CV'],
		'f_intro' => ['type' => 'paragraph', 'label' => 'Intro text'],
	];

	private function filter(string $text = '', string $from = '', string $to = '', string $respondent = '', string $where = '', string $sort = '', string $status = ''): EntryFilter {
		return EntryFilter::fromInput($text, $from, $to, $respondent, $where, $sort, $status);
	}

	public function testDefaultsShowEverythingNewestFirst(): void {
		$filter = $this->filter();
		$this->assertFalse($filter->isActive());
		$this->assertFalse($filter->needsScan());
		$this->assertTrue($filter->newestFirst);
		$this->assertSame(EntryFilter::RESPONDENT_ANY, $filter->respondent);
		$this->assertFalse($this->filter(sort: 'oldest')->newestFirst);
	}

	public function testDatesAreUtcDaysAndTheEndDayCountsInFull(): void {
		$filter = $this->filter(from: '2026-11-14', to: '2026-11-14');
		$this->assertSame(gmmktime(0, 0, 0, 11, 14, 2026), $filter->from);
		$this->assertSame(gmmktime(23, 59, 59, 11, 14, 2026), $filter->to);
		$this->assertTrue($filter->isActive());
		$this->assertFalse($filter->needsScan());
	}

	public function testStatusesAreAListTheDatabaseAnswers(): void {
		$this->assertNull($this->filter()->statuses);
		$filter = $this->filter(status: 'done,none,done');
		$this->assertSame(['done', 'none'], $filter->statuses);
		$this->assertTrue($filter->isActive());
		$this->assertFalse($filter->needsScan());
		// An id the administrator never made is not an error. It matches nothing.
		$this->assertSame(['made_up'], $this->filter(status: 'made_up')->statuses);
	}

	/** @return array<string, array{array<string, string>}> */
	public static function bad(): array {
		return [
			'status that is not an id' => [['status' => 'Done!']],
			'status list with a gap' => [['status' => 'done,,spam']],
			'not a date' => [['from' => 'yesterday']],
			'impossible date' => [['to' => '2026-02-30']],
			'wrong shape' => [['from' => '14/11/2026']],
			'range backwards' => [['from' => '2026-11-15', 'to' => '2026-11-14']],
			'unknown respondent' => [['respondent' => 'bots']],
			'unknown sort' => [['sort' => 'random']],
			'text too long' => [['text' => str_repeat('a', 201)]],
			'where not json' => [['where' => '{nope']],
			'where not a condition' => [['where' => '{"field":"f_name"}']],
			'where is a scalar' => [['where' => '"x"']],
			'where too large' => [['where' => '{"all":[' . str_repeat('1,', 5000) . '1]}']],
		];
	}

	/**
	 * @param array<string, string> $input
	 */
	#[DataProvider('bad')]
	public function testRefusesBadInput(array $input): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->filter(...$input);
	}

	public function testTextSearchesWhatAPersonSees(): void {
		$engine = new RuleEngine();
		$data = ['f_name' => 'Ada Lovelace', 'f_kind' => 'opt_sup', 'f_tags' => ['t1', 't2'], 'f_size' => 42, 'f_cv' => [['name' => 'cv-ada.pdf', 'path' => '/private/x']]];
		foreach (['lovelace', 'ADA', 'support', 'vip', 'urgent', '42', 'cv-ada'] as $needle) {
			$this->assertTrue($this->filter($needle)->matches($engine, self::FIELDS, $data, null), $needle);
		}
		// Not the internal values of choices, not file locations, not display fields, not unrelated text.
		foreach (['opt_sup', 'private', 'Intro', 'sales', 'zzz'] as $needle) {
			$this->assertFalse($this->filter($needle)->matches($engine, self::FIELDS, $data, null), $needle);
		}
		// A choice whose option was removed later still shows (and searches) as the stored value.
		$this->assertTrue($this->filter('opt_gone')->matches($engine, self::FIELDS, ['f_kind' => 'opt_gone'], null));
	}

	public function testTextAlsoMatchesTheSigningAccount(): void {
		$engine = new RuleEngine();
		$this->assertTrue($this->filter('bob')->matches($engine, self::FIELDS, [], 'Bobby'));
		$this->assertFalse($this->filter('bob')->matches($engine, self::FIELDS, [], null));
	}

	public function testAnswerConditionsUseTheRulesEngine(): void {
		$engine = new RuleEngine();
		$sales = $this->filter(where: json_encode(['all' => [['field' => 'f_kind', 'op' => 'eq', 'value' => 'opt_sal']]], JSON_THROW_ON_ERROR));
		$this->assertTrue($sales->needsScan());
		$this->assertTrue($sales->matches($engine, self::FIELDS, ['f_kind' => 'opt_sal'], null));
		$this->assertFalse($sales->matches($engine, self::FIELDS, ['f_kind' => 'opt_sup'], null));
		$this->assertFalse($sales->matches($engine, self::FIELDS, [], null));

		$either = $this->filter(where: json_encode(['any' => [['field' => 'f_tags', 'op' => 'contains', 'value' => 't2'], ['field' => 'f_size', 'op' => 'gt', 'value' => 100]]], JSON_THROW_ON_ERROR));
		$this->assertTrue($either->matches($engine, self::FIELDS, ['f_tags' => ['t2']], null));
		$this->assertTrue($either->matches($engine, self::FIELDS, ['f_size' => 200], null));
		$this->assertFalse($either->matches($engine, self::FIELDS, ['f_tags' => ['t1'], 'f_size' => 5], null));
	}

	public function testAQuestionThatVersionNeverHadCountsAsUnanswered(): void {
		$engine = new RuleEngine();
		$later = ['f_later' => ['type' => 'text', 'label' => 'Later']];
		$empty = $this->filter(where: json_encode(['all' => [['field' => 'f_later', 'op' => 'empty']]], JSON_THROW_ON_ERROR));
		$equals = $this->filter(where: json_encode(['all' => [['field' => 'f_later', 'op' => 'eq', 'value' => 'vip']]], JSON_THROW_ON_ERROR));
		$this->assertTrue($empty->matches($engine, self::FIELDS, [], null, $later));
		$this->assertFalse($equals->matches($engine, self::FIELDS, [], null, $later));
		// A question no version has any more cannot be answered, so nothing matches.
		$this->assertFalse($empty->matches($engine, self::FIELDS, [], null));
	}

	public function testAnyGroupStillMatchesOnTheBranchThatCanBeEvaluated(): void {
		$engine = new RuleEngine();
		$later = ['f_later' => ['type' => 'text', 'label' => 'Later']];
		$filter = $this->filter(where: json_encode(['any' => [['field' => 'f_name', 'op' => 'contains', 'value' => 'ada'], ['field' => 'f_later', 'op' => 'eq', 'value' => 'vip']]], JSON_THROW_ON_ERROR));
		$this->assertTrue($filter->matches($engine, self::FIELDS, ['f_name' => 'Ada'], null, $later));
		$this->assertFalse($filter->matches($engine, self::FIELDS, ['f_name' => 'Bo'], null, $later));
	}

	public function testTextAndConditionMustBothHold(): void {
		$engine = new RuleEngine();
		$filter = $this->filter('ada', where: json_encode(['all' => [['field' => 'f_kind', 'op' => 'eq', 'value' => 'opt_sal']]], JSON_THROW_ON_ERROR));
		$this->assertTrue($filter->matches($engine, self::FIELDS, ['f_name' => 'Ada', 'f_kind' => 'opt_sal'], null));
		$this->assertFalse($filter->matches($engine, self::FIELDS, ['f_name' => 'Ada', 'f_kind' => 'opt_sup'], null));
		$this->assertFalse($filter->matches($engine, self::FIELDS, ['f_name' => 'Bo', 'f_kind' => 'opt_sal'], null));
	}

	public function testReferencedFields(): void {
		$condition = ['all' => [['field' => 'f_a', 'op' => 'empty'], ['any' => [['field' => 'f_b', 'op' => 'eq', 'value' => 'x'], ['field' => 'f_a', 'op' => 'not_empty']]]]];
		$this->assertSame(['f_a', 'f_b'], EntryFilter::referencedFields($condition));
		$this->assertSame([], EntryFilter::referencedFields(null));
	}
}
