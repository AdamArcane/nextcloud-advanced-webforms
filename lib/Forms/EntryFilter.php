<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Forms;

use OCA\Webforms\Rules\FieldType;
use OCA\Webforms\Rules\RuleEngine;

/**
 * Which responses the owner wants to see (docs/SCHEMA.md section 17): free text, a submitted date
 * range, who sent them, and a condition on the answers written like a rule. Pure, so it is unit tested.
 * The date range and respondent are cheap enough for the database. Text and answer conditions look at
 * each response's own version of the form, so they are checked in PHP (see `needsScan`).
 */
final class EntryFilter {
	public const RESPONDENT_ANY = 'any';
	public const RESPONDENT_SIGNED_IN = 'signed_in';
	public const RESPONDENT_ANONYMOUS = 'anonymous';
	public const MAX_TEXT = 200;
	public const MAX_WHERE_BYTES = 8192;

	/**
	 * @param mixed $where a rule condition (`{all: [...]}`), or null
	 */
	public function __construct(
		public readonly string $text = '',
		/** Unix seconds, inclusive. */
		public readonly ?int $from = null,
		/** Unix seconds, inclusive. */
		public readonly ?int $to = null,
		public readonly string $respondent = self::RESPONDENT_ANY,
		public readonly mixed $where = null,
		public readonly bool $newestFirst = true,
		/**
		 * Only responses with one of these statuses, where `none` means without one. Null means any.
		 *
		 * @var list<string>|null
		 */
		public readonly ?array $statuses = null,
	) {
	}

	/**
	 * @param string $from a date, `YYYY-MM-DD` in UTC, or empty
	 * @param string $to a date, `YYYY-MM-DD` in UTC (the whole day counts), or empty
	 * @param string $where a JSON condition, or empty
	 * @param string $sort `newest` or `oldest`
	 * @param string $status comma separated status ids, `none` for responses without one, or empty for any
	 * @throws \InvalidArgumentException
	 */
	public static function fromInput(string $text, string $from, string $to, string $respondent, string $where, string $sort, string $status = ''): self {
		$text = trim($text);
		if (mb_strlen($text) > self::MAX_TEXT) {
			throw new \InvalidArgumentException('text_too_long');
		}
		if (!in_array($respondent === '' ? self::RESPONDENT_ANY : $respondent, [self::RESPONDENT_ANY, self::RESPONDENT_SIGNED_IN, self::RESPONDENT_ANONYMOUS], true)) {
			throw new \InvalidArgumentException('respondent_invalid');
		}
		if ($sort !== '' && !in_array($sort, ['newest', 'oldest'], true)) {
			throw new \InvalidArgumentException('sort_invalid');
		}
		$fromTime = self::day($from, false);
		$toTime = self::day($to, true);
		if ($fromTime !== null && $toTime !== null && $fromTime > $toTime) {
			throw new \InvalidArgumentException('range_invalid');
		}

		$condition = null;
		if (trim($where) !== '') {
			if (strlen($where) > self::MAX_WHERE_BYTES) {
				throw new \InvalidArgumentException('where_too_large');
			}
			$condition = json_decode($where, true, 16);
			if (!is_array($condition) || (!isset($condition['all']) && !isset($condition['any']))) {
				throw new \InvalidArgumentException('where_invalid');
			}
		}

		$statuses = null;
		if (trim($status) !== '') {
			$statuses = array_values(array_unique(explode(',', trim($status))));
			if (count($statuses) > ResponseStatuses::MAX + 1) {
				throw new \InvalidArgumentException('status_invalid');
			}
			foreach ($statuses as $one) {
				// An id that is not in the administrator's list is not an error: it matches nothing.
				if (preg_match(ResponseStatuses::ID_PATTERN, $one) !== 1) {
					throw new \InvalidArgumentException('status_invalid');
				}
			}
		}

		return new self($text, $fromTime, $toTime, $respondent === '' ? self::RESPONDENT_ANY : $respondent, $condition, $sort !== 'oldest', $statuses);
	}

	/**
	 * Whether the database alone cannot tell which responses match.
	 */
	public function needsScan(): bool {
		return $this->text !== '' || $this->where !== null;
	}

	public function isActive(): bool {
		return $this->needsScan() || $this->from !== null || $this->to !== null || $this->respondent !== self::RESPONDENT_ANY || $this->statuses !== null;
	}

	/**
	 * The text and answer conditions, for one response. The date range and respondent are not looked at.
	 *
	 * @param array<string, array<string, mixed>> $fields the fields of the version this response answered
	 * @param array<string, mixed> $data the response's answers
	 * @param array<string, array<string, mixed>> $currentFields the form's current questions. One this response's
	 *        version never had counts as unanswered, so "is empty" is true for it and "equals" is not.
	 */
	public function matches(RuleEngine $engine, array $fields, array $data, ?string $userId, array $currentFields = []): bool {
		if ($this->where !== null) {
			$conditionFields = $fields;
			foreach (self::referencedFields($this->where) as $field) {
				if (!isset($conditionFields[$field]) && isset($currentFields[$field])) {
					$conditionFields[$field] = $currentFields[$field];
				}
				if (!isset($conditionFields[$field])) {
					// A question that no version has any more cannot be answered, so the condition cannot hold.
					return false;
				}
			}
			try {
				if (!$engine->matchesCondition($conditionFields, $this->where, $data)) {
					return false;
				}
			} catch (\Throwable) {
				return false;
			}
		}
		return $this->text === '' || self::textMatches($this->text, $fields, $data, $userId);
	}

	/**
	 * Every field id a condition reads.
	 *
	 * @return list<string>
	 */
	public static function referencedFields(mixed $condition): array {
		if (!is_array($condition)) {
			return [];
		}
		$found = [];
		foreach ($condition as $key => $value) {
			if ($key === 'field' && is_string($value)) {
				$found[] = $value;
			} elseif (is_array($value)) {
				$found = array_merge($found, self::referencedFields($value));
			}
		}
		return array_values(array_unique($found));
	}

	/**
	 * Free text looks in what a person sees: answers (a choice by its label, an upload by its file
	 * names) and the account that sent it, ignoring case.
	 *
	 * @param array<string, array<string, mixed>> $fields
	 * @param array<string, mixed> $data
	 */
	private static function textMatches(string $needle, array $fields, array $data, ?string $userId): bool {
		if ($userId !== null && mb_stripos($userId, $needle) !== false) {
			return true;
		}
		foreach ($fields as $id => $field) {
			$type = (string)($field['type'] ?? '');
			if (!FieldType::isInput($type) || !array_key_exists((string)$id, $data)) {
				continue;
			}
			foreach (self::texts($field, $data[(string)$id]) as $text) {
				if (mb_stripos($text, $needle) !== false) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $field
	 * @return list<string>
	 */
	private static function texts(array $field, mixed $value): array {
		if (($field['type'] ?? null) === FieldType::SIGNATURE) {
			// A picture as text: searching it would match noise.
			return [];
		}
		$values = is_array($value) ? $value : [$value];
		$texts = [];
		foreach ($values as $one) {
			if (is_array($one)) {
				// An uploaded file: its name, never where it is stored.
				$texts[] = (string)($one['name'] ?? '');
			} elseif (is_bool($one)) {
				continue;
			} elseif (is_scalar($one)) {
				// A choice is stored by an internal value nobody sees, so it is searched by its label.
				$texts[] = self::label($field, (string)$one);
			}
		}
		return array_values(array_filter($texts, static fn (string $text): bool => $text !== ''));
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private static function label(array $field, string $value): string {
		foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
			if (is_array($option) && (string)($option['value'] ?? '') === $value && (string)($option['label'] ?? '') !== '') {
				return (string)$option['label'];
			}
		}
		return $value;
	}

	private static function day(string $date, bool $endOfDay): ?int {
		$date = trim($date);
		if ($date === '') {
			return null;
		}
		$parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1
			? \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'))
			: false;
		if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
			throw new \InvalidArgumentException('date_invalid');
		}
		return $parsed->getTimestamp() + ($endOfDay ? 86399 : 0);
	}
}
