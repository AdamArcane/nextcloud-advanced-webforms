<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

use OCA\Webforms\Rules\FieldType;

/**
 * Adds responses up into a summary (docs/SCHEMA.md section 17.2): how many arrived over time, how often
 * each choice was picked, and the range and average of number answers. Each response is read against
 * its own version of the form. Pure, so it is unit tested.
 */
final class EntrySummary {
	/** A timeline longer than this many days is shown by month. */
	public const MAX_DAYS = 92;

	private int $total = 0;
	/** @var array<string, int> `YYYY-MM-DD` (UTC) => responses */
	private array $days = [];
	/** @var array<string, array<string, mixed>> */
	private array $fields = [];

	/**
	 * @param int $versionId the version this response answered (a newer version's labels win)
	 * @param list<string> $order that version's field ids in form order
	 * @param array<string, array<string, mixed>> $fields that version's fields
	 * @param array<string, mixed> $data the answers
	 */
	public function add(int $versionId, array $order, array $fields, array $data, int $submittedAt): void {
		$this->total++;
		$day = gmdate('Y-m-d', $submittedAt);
		$this->days[$day] = ($this->days[$day] ?? 0) + 1;

		foreach ($order as $position => $id) {
			$field = $fields[$id] ?? null;
			$type = is_array($field) ? FieldType::valueType($field) : '';
			$kind = match ($type) {
				FieldType::SELECT, FieldType::RADIO, FieldType::CHECKBOXES => 'choice',
				FieldType::NUMBER, FieldType::RATING, FieldType::SCALE, FieldType::SLIDER => 'number',
				FieldType::CHECKBOX, FieldType::CONSENT => 'yesno',
				default => null,
			};
			if ($kind === null) {
				continue;
			}
			$entry = $this->fields[$id] ?? ['kind' => $kind, 'type' => $type, 'version' => -1, 'position' => 0, 'label' => $id,
				'answered' => 0, 'options' => [], 'count' => 0, 'sum' => 0.0, 'min' => null, 'max' => null, 'yes' => 0, 'no' => 0];
			if ($entry['kind'] !== $kind) {
				// The question changed its nature between versions: the newest version decides what it is.
				if ($versionId < $entry['version']) {
					continue;
				}
				$entry = ['kind' => $kind, 'answered' => 0, 'options' => [], 'count' => 0, 'sum' => 0.0, 'min' => null, 'max' => null, 'yes' => 0, 'no' => 0] + $entry;
			}
			$newer = $versionId >= $entry['version'];
			if ($newer) {
				$entry['version'] = $versionId;
				$entry['position'] = $position;
				$entry['type'] = $type;
				$entry['label'] = is_string($field['label'] ?? null) && trim($field['label']) !== '' ? $field['label'] : $id;
			}

			if ($kind === 'choice') {
				// Every option this version offers is listed, even with no picks, in the newest version's order.
				foreach (array_values(is_array($field['options'] ?? null) ? $field['options'] : []) as $index => $option) {
					if (!is_array($option) || !is_scalar($option['value'] ?? null)) {
						continue;
					}
					$value = (string)$option['value'];
					$known = $entry['options'][$value] ?? ['label' => $value, 'count' => 0, 'version' => -1, 'position' => 0];
					if ($versionId >= $known['version']) {
						$known['version'] = $versionId;
						$known['position'] = $index;
						$known['label'] = is_string($option['label'] ?? null) && $option['label'] !== '' ? $option['label'] : $value;
					}
					$entry['options'][$value] = $known;
				}
			}

			$value = $data[$id] ?? null;
			if ($kind === 'choice') {
				$picked = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
				$picked = array_values(array_unique(array_filter(array_map(static fn (mixed $one): ?string => is_scalar($one) ? (string)$one : null, $picked), static fn (?string $one): bool => $one !== null && $one !== '')));
				if ($picked !== []) {
					$entry['answered']++;
				}
				foreach ($picked as $one) {
					// A stored value with no option any more still counts, under its own name.
					$entry['options'][$one] ??= ['label' => $one, 'count' => 0, 'version' => -1, 'position' => PHP_INT_MAX];
					$entry['options'][$one]['count']++;
				}
			} elseif ($kind === 'number') {
				if (is_int($value) || is_float($value)) {
					$entry['answered']++;
					$entry['count']++;
					$entry['sum'] += $value;
					$entry['min'] = $entry['min'] === null ? $value : min($entry['min'], $value);
					$entry['max'] = $entry['max'] === null ? $value : max($entry['max'], $value);
				}
			} elseif (is_bool($value)) {
				$entry['answered']++;
				$entry[$value ? 'yes' : 'no']++;
			}
			$this->fields[$id] = $entry;
		}
	}

	/**
	 * @return array{total: int, timeline: array{unit: string, buckets: list<array{start: string, count: int}>}, fields: list<array<string, mixed>>}
	 */
	public function result(): array {
		$fields = $this->fields;
		// Newest version's questions first in its form order, then questions only older versions had.
		uasort($fields, static fn (array $a, array $b): int => [$b['version'], $a['position']] <=> [$a['version'], $b['position']]);

		$list = [];
		foreach ($fields as $id => $field) {
			$item = ['id' => (string)$id, 'label' => $field['label'], 'type' => $field['type'], 'kind' => $field['kind'], 'answered' => $field['answered']];
			if ($field['kind'] === 'choice') {
				$options = $field['options'];
				uasort($options, static fn (array $a, array $b): int => [$b['version'], $a['position']] <=> [$a['version'], $b['position']]);
				$item['options'] = [];
				foreach ($options as $value => $option) {
					$item['options'][] = ['value' => (string)$value, 'label' => $option['label'], 'count' => $option['count']];
				}
			} elseif ($field['kind'] === 'number') {
				$item += [
					'min' => $field['min'],
					'max' => $field['max'],
					'average' => $field['count'] > 0 ? round($field['sum'] / $field['count'], 2) : null,
					'sum' => $field['count'] > 0 ? round($field['sum'], 2) : null,
				];
			} else {
				$item += ['yes' => $field['yes'], 'no' => $field['no']];
			}
			$list[] = $item;
		}

		return ['total' => $this->total, 'timeline' => self::timeline($this->days), 'fields' => $list];
	}

	/**
	 * Responses per day from the first day that has one to the last, with the empty days in between, or
	 * per month when that would be more than MAX_DAYS columns.
	 *
	 * @param array<string, int> $days
	 * @return array{unit: string, buckets: list<array{start: string, count: int}>}
	 */
	public static function timeline(array $days): array {
		if ($days === []) {
			return ['unit' => 'day', 'buckets' => []];
		}
		ksort($days);
		$utc = new \DateTimeZone('UTC');
		$first = new \DateTimeImmutable((string)array_key_first($days), $utc);
		$last = new \DateTimeImmutable((string)array_key_last($days), $utc);
		$buckets = [];
		if ((int)$first->diff($last)->days + 1 <= self::MAX_DAYS) {
			for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
				$buckets[] = ['start' => $day->format('Y-m-d'), 'count' => $days[$day->format('Y-m-d')] ?? 0];
			}
			return ['unit' => 'day', 'buckets' => $buckets];
		}
		$months = [];
		foreach ($days as $day => $count) {
			$months[substr($day, 0, 7)] = ($months[substr($day, 0, 7)] ?? 0) + $count;
		}
		$end = $last->modify('first day of this month');
		for ($month = $first->modify('first day of this month'); $month <= $end; $month = $month->modify('+1 month')) {
			$buckets[] = ['start' => $month->format('Y-m-d'), 'count' => $months[$month->format('Y-m')] ?? 0];
		}
		return ['unit' => 'month', 'buckets' => $buckets];
	}
}
