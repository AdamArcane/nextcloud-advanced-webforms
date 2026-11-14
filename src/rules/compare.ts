/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { NormalizedValue } from './types.ts'

import { normalize } from './normalize.ts'

// Mirrors lib/Rules/Comparator.php. Semantics: docs/SCHEMA.md section 6.2.

/**
 * Evaluate one leaf condition.
 *
 * @param type the source field's type
 * @param op the operator
 * @param normalized the source field's normalized value, null when empty
 * @param ruleValue the rule's raw `value`
 */
export function compare(type: string, op: string, normalized: NormalizedValue, ruleValue: unknown): boolean {
	const empty = normalized === null

	switch (op) {
		case 'empty':
			return empty
		case 'not_empty':
			return !empty
		case 'neq':
			return empty || !equals(type, normalized, ruleValue)
		case 'not_contains':
			return empty || !contains(type, normalized, ruleValue)
		case 'not_in':
			return empty || !isIn(type, normalized, ruleValue)
		case 'contains_none':
			return empty || !containsAny(normalized, ruleValue)
		default:
			break
	}

	// Every remaining operator is false against an empty value.
	if (empty) {
		return false
	}

	switch (op) {
		case 'eq':
			return equals(type, normalized, ruleValue)
		case 'contains':
			return contains(type, normalized, ruleValue)
		case 'in':
			return isIn(type, normalized, ruleValue)
		case 'contains_any':
			return containsAny(normalized, ruleValue)
		case 'contains_all':
			return containsAll(normalized, ruleValue)
		case 'gt':
		case 'gte':
		case 'lt':
		case 'lte':
			return ordered(type, op, normalized, ruleValue)
		default:
			return false
	}
}

function equals(type: string, normalized: NormalizedValue, ruleValue: unknown): boolean {
	const expected = normalize(type, ruleValue)
	return expected !== null && expected === normalized
}

function contains(type: string, normalized: NormalizedValue, ruleValue: unknown): boolean {
	if (type === 'checkboxes') {
		return Array.isArray(normalized) && typeof ruleValue === 'string' && normalized.includes(ruleValue)
	}
	const needle = normalize(type, ruleValue)
	return typeof normalized === 'string' && typeof needle === 'string' && normalized.includes(needle)
}

/** For checkboxes: at least one of the listed option values is selected. */
function containsAny(normalized: NormalizedValue, ruleValue: unknown): boolean {
	if (!Array.isArray(normalized) || !Array.isArray(ruleValue)) {
		return false
	}
	return ruleValue.some((item) => typeof item === 'string' && normalized.includes(item))
}

/** For checkboxes: every listed option value is selected. An empty list is never true. */
function containsAll(normalized: NormalizedValue, ruleValue: unknown): boolean {
	if (!Array.isArray(normalized) || !Array.isArray(ruleValue) || ruleValue.length === 0) {
		return false
	}
	return ruleValue.every((item) => typeof item === 'string' && normalized.includes(item))
}

function isIn(type: string, normalized: NormalizedValue, ruleValue: unknown): boolean {
	if (!Array.isArray(ruleValue)) {
		return false
	}
	return ruleValue.some((item) => {
		const candidate = normalize(type, item)
		return candidate !== null && candidate === normalized
	})
}

function ordered(type: string, op: string, normalized: NormalizedValue, ruleValue: unknown): boolean {
	const other = normalize(type, ruleValue)

	let cmp: number
	if (typeof normalized === 'number' && typeof other === 'number') {
		cmp = normalized < other ? -1 : (normalized > other ? 1 : 0)
	} else if (typeof normalized === 'string' && typeof other === 'string') {
		// Dates and times: lexical order equals chronological order for the fixed formats.
		cmp = normalized < other ? -1 : (normalized > other ? 1 : 0)
	} else {
		return false
	}

	switch (op) {
		case 'gt':
			return cmp > 0
		case 'gte':
			return cmp >= 0
		case 'lt':
			return cmp < 0
		case 'lte':
			return cmp <= 0
		default:
			return false
	}
}
