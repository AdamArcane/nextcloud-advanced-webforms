<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Tests\Unit\Forms;

use OCA\Webforms\Forms\EntrySummary;
use PHPUnit\Framework\TestCase;

final class EntrySummaryTest extends TestCase {
	private const DAY = 86400;
	private const T0 = 1794700800; // 2026-11-15 00:00:00 UTC

	private const V1 = [
		'f_name' => ['type' => 'text', 'label' => 'Name'],
		'f_kind' => ['type' => 'select', 'label' => 'Kind', 'options' => [['value' => 'sup', 'label' => 'Support'], ['value' => 'sal', 'label' => 'Sales'], ['value' => 'old', 'label' => 'Legacy']]],
		'f_ok' => ['type' => 'checkbox', 'label' => 'Agree'],
	];
	private const V2 = [
		'f_name' => ['type' => 'text', 'label' => 'Name'],
		'f_score' => ['type' => 'number', 'label' => 'Score'],
		'f_kind' => ['type' => 'radio', 'label' => 'Topic', 'options' => [['value' => 'sal', 'label' => 'Sales team'], ['value' => 'sup', 'label' => 'Support']]],
		'f_tags' => ['type' => 'checkboxes', 'label' => 'Tags', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
		'f_ok' => ['type' => 'checkbox', 'label' => 'Agree'],
	];

	/**
	 * @param array<string, mixed> $data
	 */
	private static function add(EntrySummary $summary, int $version, array $data, int $at = self::T0): void {
		$fields = $version === 1 ? self::V1 : self::V2;
		$summary->add($version, array_keys($fields), $fields, $data, $at);
	}

	/**
	 * @param array{fields: list<array<string, mixed>>} $result
	 * @return array<string, mixed>
	 */
	private static function field(array $result, string $id): array {
		foreach ($result['fields'] as $field) {
			if ($field['id'] === $id) {
				return $field;
			}
		}
		self::fail('no field ' . $id);
	}

	public function testNothingYet(): void {
		$result = (new EntrySummary())->result();
		$this->assertSame(['total' => 0, 'timeline' => ['unit' => 'day', 'buckets' => []], 'fields' => []], $result);
	}

	public function testCountsChoicesAcrossVersionsWithTheNewestLabelsAndOrder(): void {
		$summary = new EntrySummary();
		self::add($summary, 1, ['f_kind' => 'sup']);
		self::add($summary, 1, ['f_kind' => 'old']);
		self::add($summary, 2, ['f_kind' => 'sal']);
		self::add($summary, 2, ['f_kind' => 'sal']);
		self::add($summary, 2, []);
		$kind = self::field($summary->result(), 'f_kind');
		$this->assertSame('Topic', $kind['label']);
		$this->assertSame('radio', $kind['type']);
		$this->assertSame(4, $kind['answered']);
		// Version 2's options in its order, then the option only version 1 had.
		$this->assertSame([
			['value' => 'sal', 'label' => 'Sales team', 'count' => 2],
			['value' => 'sup', 'label' => 'Support', 'count' => 1],
			['value' => 'old', 'label' => 'Legacy', 'count' => 1],
		], $kind['options']);
	}

	public function testCheckboxesCountEachPickAndOptionsNobodyPickedAreListed(): void {
		$summary = new EntrySummary();
		self::add($summary, 2, ['f_tags' => ['a', 'b']]);
		self::add($summary, 2, ['f_tags' => ['a', 'a']]);
		self::add($summary, 2, ['f_tags' => []]);
		$tags = self::field($summary->result(), 'f_tags');
		$this->assertSame(2, $tags['answered']);
		$this->assertSame([['value' => 'a', 'label' => 'A', 'count' => 2], ['value' => 'b', 'label' => 'B', 'count' => 1]], $tags['options']);

		$quiet = new EntrySummary();
		self::add($quiet, 2, []);
		$this->assertSame([0, 0], array_column(self::field($quiet->result(), 'f_tags')['options'], 'count'));
	}

	public function testAStoredValueWithNoOptionStillCounts(): void {
		$summary = new EntrySummary();
		self::add($summary, 2, ['f_kind' => 'gone']);
		$options = self::field($summary->result(), 'f_kind')['options'];
		$this->assertSame(['value' => 'gone', 'label' => 'gone', 'count' => 1], end($options));
	}

	public function testNumbers(): void {
		$summary = new EntrySummary();
		foreach ([4, 5, 2.5, null, 'x'] as $score) {
			self::add($summary, 2, ['f_score' => $score]);
		}
		$score = self::field($summary->result(), 'f_score');
		$this->assertSame(3, $score['answered']);
		$this->assertSame(2.5, $score['min']);
		$this->assertSame(5, $score['max']);
		$this->assertSame(3.83, $score['average']);
		$this->assertSame(11.5, $score['sum']);

		$empty = new EntrySummary();
		self::add($empty, 2, []);
		$this->assertNull(self::field($empty->result(), 'f_score')['average']);
	}

	public function testYesNo(): void {
		$summary = new EntrySummary();
		self::add($summary, 1, ['f_ok' => true]);
		self::add($summary, 2, ['f_ok' => true]);
		self::add($summary, 2, ['f_ok' => false]);
		self::add($summary, 2, []);
		$ok = self::field($summary->result(), 'f_ok');
		$this->assertSame([3, 2, 1], [$ok['answered'], $ok['yes'], $ok['no']]);
	}

	public function testTextQuestionsAreNotSummarisedAndOrderFollowsTheNewestForm(): void {
		$summary = new EntrySummary();
		self::add($summary, 1, ['f_name' => 'Ada']);
		self::add($summary, 2, ['f_name' => 'Bo']);
		$this->assertSame(['f_score', 'f_kind', 'f_tags', 'f_ok'], array_column($summary->result()['fields'], 'id'));
	}

	public function testTimelineByDayFillsTheGaps(): void {
		$summary = new EntrySummary();
		self::add($summary, 2, [], self::T0 + 100);
		self::add($summary, 2, [], self::T0 + self::DAY - 1);
		self::add($summary, 2, [], self::T0 + 3 * self::DAY);
		$this->assertSame(['unit' => 'day', 'buckets' => [
			['start' => '2026-11-15', 'count' => 2],
			['start' => '2026-11-16', 'count' => 0],
			['start' => '2026-11-17', 'count' => 0],
			['start' => '2026-11-18', 'count' => 1],
		]], $summary->result()['timeline']);
		$this->assertSame(3, $summary->result()['total']);
	}

	public function testALongTimelineGoesByMonth(): void {
		$timeline = EntrySummary::timeline(['2026-01-31' => 2, '2026-02-01' => 1, '2026-05-10' => 4]);
		$this->assertSame('month', $timeline['unit']);
		$this->assertSame([
			['start' => '2026-01-01', 'count' => 2],
			['start' => '2026-02-01', 'count' => 1],
			['start' => '2026-03-01', 'count' => 0],
			['start' => '2026-04-01', 'count' => 0],
			['start' => '2026-05-01', 'count' => 4],
		], $timeline['buckets']);
		// Exactly the limit still goes by day.
		$this->assertSame('day', EntrySummary::timeline(['2026-01-01' => 1, '2026-04-02' => 1])['unit']);
		$this->assertSame('month', EntrySummary::timeline(['2026-01-01' => 1, '2026-04-03' => 1])['unit']);
	}
}
