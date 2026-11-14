<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Rules;

/**
 * Field types and the rule operators each input type supports.
 * See docs/SCHEMA.md sections 4 and 6.
 */
final class FieldType {
	public const TEXT = 'text';
	public const TEXTAREA = 'textarea';
	public const EMAIL = 'email';
	public const PHONE = 'phone';
	public const NUMBER = 'number';
	public const SELECT = 'select';
	public const RADIO = 'radio';
	public const CHECKBOXES = 'checkboxes';
	public const CHECKBOX = 'checkbox';
	public const DATE = 'date';
	public const TIME = 'time';

	public const HEADING = 'heading';
	public const PARAGRAPH = 'paragraph';
	public const DIVIDER = 'divider';

	private const TEXT_OPERATORS = ['eq', 'neq', 'contains', 'not_contains', 'in', 'not_in', 'empty', 'not_empty'];
	private const NUMBER_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'empty', 'not_empty'];
	private const CHOICE_OPERATORS = ['eq', 'neq', 'in', 'not_in', 'empty', 'not_empty'];
	private const MULTI_OPERATORS = ['contains', 'not_contains', 'empty', 'not_empty'];
	private const BOOLEAN_OPERATORS = ['eq', 'neq'];
	private const ORDERED_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'empty', 'not_empty'];

	/**
	 * Input types (they produce a value) mapped to their allowed operators.
	 *
	 * @var array<string, list<string>>
	 */
	public const OPERATORS = [
		self::TEXT => self::TEXT_OPERATORS,
		self::TEXTAREA => self::TEXT_OPERATORS,
		self::EMAIL => self::TEXT_OPERATORS,
		self::PHONE => self::TEXT_OPERATORS,
		self::NUMBER => self::NUMBER_OPERATORS,
		self::SELECT => self::CHOICE_OPERATORS,
		self::RADIO => self::CHOICE_OPERATORS,
		self::CHECKBOXES => self::MULTI_OPERATORS,
		self::CHECKBOX => self::BOOLEAN_OPERATORS,
		self::DATE => self::ORDERED_OPERATORS,
		self::TIME => self::ORDERED_OPERATORS,
	];

	/** @var list<string> */
	public const DISPLAY_TYPES = [self::HEADING, self::PARAGRAPH, self::DIVIDER];

	public static function isInput(string $type): bool {
		return array_key_exists($type, self::OPERATORS);
	}

	public static function isDisplay(string $type): bool {
		return in_array($type, self::DISPLAY_TYPES, true);
	}

	/**
	 * @return list<string> empty for display and unknown types
	 */
	public static function operatorsFor(string $type): array {
		return self::OPERATORS[$type] ?? [];
	}
}
