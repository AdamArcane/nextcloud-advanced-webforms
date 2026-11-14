/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Mirrors lib/Rules/FieldType.php.

const TEXT_OPERATORS = ['eq', 'neq', 'contains', 'not_contains', 'in', 'not_in', 'empty', 'not_empty']
const NUMBER_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'empty', 'not_empty']
const CHOICE_OPERATORS = ['eq', 'neq', 'in', 'not_in', 'empty', 'not_empty']
const MULTI_OPERATORS = ['contains', 'not_contains', 'empty', 'not_empty']
const BOOLEAN_OPERATORS = ['eq', 'neq']
const FILE_OPERATORS = ['empty', 'not_empty']
const ORDERED_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'empty', 'not_empty']

/** Input types (they produce a value) mapped to their allowed operators. */
const OPERATORS = new Map<string, readonly string[]>([
	['text', TEXT_OPERATORS],
	['textarea', TEXT_OPERATORS],
	['email', TEXT_OPERATORS],
	['phone', TEXT_OPERATORS],
	['number', NUMBER_OPERATORS],
	['select', CHOICE_OPERATORS],
	['radio', CHOICE_OPERATORS],
	['checkboxes', MULTI_OPERATORS],
	['checkbox', BOOLEAN_OPERATORS],
	['date', ORDERED_OPERATORS],
	['time', ORDERED_OPERATORS],
	['file', FILE_OPERATORS],
])

export function isInputType(type: string): boolean {
	return OPERATORS.has(type)
}

/** Operators allowed for a type, empty for display and unknown types. */
export function operatorsFor(type: string): readonly string[] {
	return OPERATORS.get(type) ?? []
}
