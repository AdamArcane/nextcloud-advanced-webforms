<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Rules;

/**
 * Field types and the rule operators each input type supports.
 * See docs/SCHEMA.md sections 4 and 6.
 */
final class FieldType {
	public const TEXT = 'text';
	public const TEXTAREA = 'textarea';
	public const EMAIL = 'email';
	public const PHONE = 'phone';
	public const URL = 'url';
	public const NUMBER = 'number';
	public const SELECT = 'select';
	public const RADIO = 'radio';
	public const CHECKBOXES = 'checkboxes';
	public const CHECKBOX = 'checkbox';
	public const DATE = 'date';
	public const TIME = 'time';
	/** One date and time, `YYYY-MM-DDTHH:MM` (docs/SCHEMA.md section 4.8). */
	public const DATETIME = 'datetime';
	/** Stars: a whole number from 1 to the field's `max`. */
	public const RATING = 'rating';
	/** A whole number on a line from `min` to `max`, with a label at each end. */
	public const SCALE = 'scale';
	/** A checkbox beside a text the respondent agrees to. */
	public const CONSENT = 'consent';
	public const FILE = 'file';
	/** Never shown to respondents; filled from its default or the address (docs/SCHEMA.md section 4.7). */
	public const HIDDEN = 'hidden';
	/** One answer per row of a grid of choices (docs/SCHEMA.md section 4.10). */
	public const MATRIX = 'matrix';
	/** A drawn signature, stored as a small PNG (docs/SCHEMA.md section 4.10). */
	public const SIGNATURE = 'signature';
	/** A number picked by dragging a handle along a line. */
	public const SLIDER = 'slider';
	/** A colour, `#rrggbb`. */
	public const COLOR = 'color';
	/** A first and a last date, `{start, end}`. */
	public const DATE_RANGE = 'daterange';
	/** A person's name in parts, such as first and last. */
	public const NAME = 'name';
	/** A postal address in parts. */
	public const ADDRESS = 'address';

	public const HEADING = 'heading';
	public const PARAGRAPH = 'paragraph';
	public const DIVIDER = 'divider';
	/** A picture from an address or from the owner's Files (docs/SCHEMA.md section 4.9). */
	public const IMAGE = 'image';

	private const TEXT_OPERATORS = ['eq', 'neq', 'contains', 'not_contains', 'in', 'not_in', 'empty', 'not_empty'];
	private const NUMBER_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'empty', 'not_empty'];
	private const CHOICE_OPERATORS = ['eq', 'neq', 'in', 'not_in', 'empty', 'not_empty'];
	private const MULTI_OPERATORS = ['contains', 'not_contains', 'contains_any', 'contains_all', 'contains_none', 'empty', 'not_empty'];
	private const BOOLEAN_OPERATORS = ['eq', 'neq'];
	/** Upload fields hold a list of uploaded files: a rule can only ask whether any were added. */
	private const FILE_OPERATORS = ['empty', 'not_empty'];
	private const COLOR_OPERATORS = ['eq', 'neq', 'empty', 'not_empty'];
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
		self::URL => self::TEXT_OPERATORS,
		self::NUMBER => self::NUMBER_OPERATORS,
		self::SELECT => self::CHOICE_OPERATORS,
		self::RADIO => self::CHOICE_OPERATORS,
		self::CHECKBOXES => self::MULTI_OPERATORS,
		self::CHECKBOX => self::BOOLEAN_OPERATORS,
		self::DATE => self::ORDERED_OPERATORS,
		self::TIME => self::ORDERED_OPERATORS,
		self::DATETIME => self::ORDERED_OPERATORS,
		self::RATING => self::NUMBER_OPERATORS,
		self::SCALE => self::NUMBER_OPERATORS,
		self::CONSENT => self::BOOLEAN_OPERATORS,
		self::FILE => self::FILE_OPERATORS,
		self::HIDDEN => self::TEXT_OPERATORS,
		self::MATRIX => self::FILE_OPERATORS,
		self::SIGNATURE => self::FILE_OPERATORS,
		self::SLIDER => self::NUMBER_OPERATORS,
		self::COLOR => self::COLOR_OPERATORS,
		self::DATE_RANGE => self::FILE_OPERATORS,
		self::NAME => self::FILE_OPERATORS,
		self::ADDRESS => self::FILE_OPERATORS,
	];

	/** @var list<string> */
	public const DISPLAY_TYPES = [self::HEADING, self::PARAGRAPH, self::DIVIDER, self::IMAGE];

	public static function isInput(string $type): bool {
		return array_key_exists($type, self::OPERATORS);
	}

	/** Types whose answer is a number. */
	public static function isNumeric(string $type): bool {
		return $type === self::NUMBER || $type === self::RATING || $type === self::SCALE || $type === self::SLIDER;
	}

	/** Types whose answer is ticked or not. */
	public static function isBoolean(string $type): bool {
		return $type === self::CHECKBOX || $type === self::CONSENT;
	}

	/** Types whose answer is a set of named parts, an array with text keys. */
	public static function isComposite(string $type): bool {
		return in_array($type, [self::MATRIX, self::DATE_RANGE, self::NAME, self::ADDRESS], true);
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
