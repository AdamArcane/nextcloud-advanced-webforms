/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Mirrors lib/Rules/FieldType.php.

const TEXT_OPERATORS = ['eq', 'neq', 'contains', 'not_contains', 'in', 'not_in', 'empty', 'not_empty']
const NUMBER_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'empty', 'not_empty']
const CHOICE_OPERATORS = ['eq', 'neq', 'in', 'not_in', 'empty', 'not_empty']
const MULTI_OPERATORS = ['contains', 'not_contains', 'contains_any', 'contains_all', 'contains_none', 'empty', 'not_empty']
const BOOLEAN_OPERATORS = ['eq', 'neq']
const FILE_OPERATORS = ['empty', 'not_empty']
const ORDERED_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'empty', 'not_empty']

/** Input types (they produce a value) mapped to their allowed operators. */
const OPERATORS = new Map<string, readonly string[]>([
	['text', TEXT_OPERATORS],
	['textarea', TEXT_OPERATORS],
	['email', TEXT_OPERATORS],
	['phone', TEXT_OPERATORS],
	['url', TEXT_OPERATORS],
	['number', NUMBER_OPERATORS],
	['select', CHOICE_OPERATORS],
	['radio', CHOICE_OPERATORS],
	['checkboxes', MULTI_OPERATORS],
	['checkbox', BOOLEAN_OPERATORS],
	['date', ORDERED_OPERATORS],
	['time', ORDERED_OPERATORS],
	['datetime', ORDERED_OPERATORS],
	['rating', NUMBER_OPERATORS],
	['scale', NUMBER_OPERATORS],
	['consent', BOOLEAN_OPERATORS],
	['file', FILE_OPERATORS],
	['hidden', TEXT_OPERATORS],
])

export function isInputType(type: string): boolean {
	return OPERATORS.has(type)
}

/** Operators allowed for a type, empty for display and unknown types. */
export function operatorsFor(type: string): readonly string[] {
	return OPERATORS.get(type) ?? []
}

/** Types whose answer is a number. */
export function isNumericType(type: string): boolean {
	return type === 'number' || type === 'rating' || type === 'scale'
}

/** Types whose answer is ticked or not. */
export function isBooleanType(type: string): boolean {
	return type === 'checkbox' || type === 'consent'
}

/** Types whose answer is a date, a time, or both. */
export function isTemporalType(type: string): boolean {
	return type === 'date' || type === 'time' || type === 'datetime'
}

/** The native input type that edits a date, a time, or both. */
export function temporalInputType(type: string): string {
	return type === 'datetime' ? 'datetime-local' : type
}

/** The values a rating or a scale offers, in order (docs/SCHEMA.md section 4.8). */
export function steps(field: { type: string, min?: unknown, max?: unknown }): number[] {
	const max = typeof field.max === 'number' && Number.isInteger(field.max) ? Math.min(10, Math.max(2, field.max)) : 5
	const min = field.type === 'scale' && field.min === 0 ? 0 : 1
	return Array.from({ length: max - min + 1 }, (_, index) => min + index)
}
