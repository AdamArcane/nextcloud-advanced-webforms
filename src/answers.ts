/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Answers made of named parts: a matrix, a date range, a name and an address (docs/SCHEMA.md 4.10).
// The form keeps them as a plain object while the respondent edits; empty parts stay as empty text
// and the server drops them.

import type { FormField } from './types.ts'

import { REQUIRED_PARTS, isCompositeType, partsOf } from './rules/fieldTypes.ts'

/** The value a composite field starts with, from its default when it has a valid one. */
export function startingParts(field: FormField): Record<string, unknown> {
	const given = field.defaultValue
	if (typeof given === 'object' && given !== null && !Array.isArray(given)) {
		return { ...given }
	}
	if (field.type === 'daterange') {
		return { start: '', end: '' }
	}
	if (field.type === 'matrix') {
		return {}
	}
	return Object.fromEntries(partsOf(field).map((part) => [part, '']))
}

function filled(value: unknown): boolean {
	return Array.isArray(value) ? value.length > 0 : (typeof value === 'string' && value.trim() !== '')
}

/** `YYYY-MM-DD` plus whole days, in UTC so a clock change never shifts it. Empty when `date` is not a date. */
export function addDays(date: string, days: number): string {
	const parsed = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date)
	if (!parsed) {
		return ''
	}
	return new Date(Date.UTC(Number(parsed[1]), Number(parsed[2]) - 1, Number(parsed[3]) + days)).toISOString().slice(0, 10)
}

/** Whether a composite answer has anything in it. */
export function hasParts(value: unknown): boolean {
	return typeof value === 'object' && value !== null && !Array.isArray(value) && Object.values(value).some(filled)
}

/**
 * For a required field only: whether everything that has to be answered was. A name needs its first
 * and last part, an address its street, city, postal code and country, a matrix every row, a date
 * range both dates. Mirrors FieldValueValidator::isComplete.
 */
export function isComplete(field: FormField, value: unknown): boolean {
	if (!isCompositeType(field.type) || !hasParts(value)) {
		return true
	}
	const given = value as Record<string, unknown>
	if (field.type === 'daterange') {
		return filled(given.start) && filled(given.end)
	}
	if (field.type === 'matrix') {
		return (field.questions ?? []).every((question) => filled(given[question.value]))
	}
	const needed = REQUIRED_PARTS[field.type] ?? []
	return partsOf(field).filter((part) => needed.includes(part)).every((part) => filled(given[part]))
}
