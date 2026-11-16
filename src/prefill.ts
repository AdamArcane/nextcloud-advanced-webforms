/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Filling fields from the address the form was opened with (docs/SCHEMA.md section 4.7).

import type { FormField } from './types.ts'

/** Limits from lib/Forms/FieldValueValidator.php, so a prefilled value is one the server takes. */
const MAX_TEXT = 2000
const MAX_TEXTAREA = 20000
const MAX_EMAIL = 254
const MAX_URL = 2048

const CHECKED = new Set(['1', 'true', 'yes', 'on'])
const UNCHECKED = new Set(['0', 'false', 'no', 'off'])

/**
 * The starting value the address gives a field, or undefined when it gives none or one the field would
 * not accept: then the field keeps its default. The value has the shape the form keeps answers in (text
 * as a string, checkboxes as a list of option values, a checkbox as a boolean).
 */
export function prefillValue(fieldId: string, field: FormField, params: URLSearchParams): unknown {
	// The parameter is the field id: a readable id gives a readable link.
	const name = fieldId
	if (field.type === 'file' || !params.has(name)) {
		return undefined
	}
	const all = params.getAll(name)
	const raw = (all[0] ?? '').trim()
	switch (field.type) {
		case 'text':
		case 'hidden':
			return text(field, raw, MAX_TEXT)
		case 'textarea':
			return text(field, raw, MAX_TEXTAREA)
		case 'email':
			return raw.length <= MAX_EMAIL && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw) ? raw : undefined
		case 'phone':
			return /^\+?[0-9 ().-]+$/.test(raw) ? raw : undefined
		case 'url':
			return raw.length <= MAX_URL && /^[a-z][a-z0-9+.-]*:/i.test(raw) && !/[\s<>"]/.test(raw) ? raw : undefined
		case 'number':
			return number(field, raw)
		case 'select':
		case 'radio':
			return option(field, raw)
		case 'checkboxes':
			return options(field, all)
		case 'checkbox': {
			const word = raw.toLowerCase()
			return CHECKED.has(word) ? true : UNCHECKED.has(word) ? false : undefined
		}
		case 'date':
			return /^\d{4}-\d{2}-\d{2}$/.test(raw) && inRange(field, raw) ? raw : undefined
		case 'time':
			return /^\d{2}:\d{2}$/.test(raw) && inRange(field, raw) ? raw : undefined
		default:
			return undefined
	}
}

function text(field: FormField, raw: string, cap: number): string | undefined {
	const max = typeof field.maxLength === 'number' && field.maxLength >= 1 ? Math.min(cap, field.maxLength) : cap
	const min = typeof field.minLength === 'number' ? field.minLength : 0
	return raw !== '' && [...raw].length <= max && [...raw].length >= min ? raw : undefined
}

/** Numbers are kept as text while the respondent edits them, like a typed answer. */
function number(field: FormField, raw: string): string | undefined {
	if (!/^-?\d+(\.\d+)?$/.test(raw)) {
		return undefined
	}
	const value = Number(raw)
	const min = typeof field.min === 'number' ? field.min : null
	const max = typeof field.max === 'number' ? field.max : null
	if ((min !== null && value < min) || (max !== null && value > max)) {
		return undefined
	}
	if (typeof field.step === 'number' && field.step > 0) {
		const ratio = (value - (min ?? 0)) / field.step
		if (Math.abs(ratio - Math.round(ratio)) > 1e-9 * Math.max(1, Math.abs(ratio))) {
			return undefined
		}
	}
	return raw
}

/** An option's value, found by its value or, ignoring case, by its label. */
function matchOption(field: FormField, raw: string): string | undefined {
	const list = field.options ?? []
	const lower = raw.toLowerCase()
	return list.find((entry) => entry.value === raw)?.value
		?? list.find((entry) => entry.label.trim().toLowerCase() === lower)?.value
}

function option(field: FormField, raw: string): string | undefined {
	return raw === '' ? undefined : matchOption(field, raw)
}

/** Several options, by repeating the parameter or separating them with commas. Unknown ones are left out. */
function options(field: FormField, all: string[]): string[] | undefined {
	const picked: string[] = []
	for (const part of all.flatMap((entry) => entry.split(','))) {
		const value = matchOption(field, part.trim())
		if (value !== undefined && !picked.includes(value)) {
			picked.push(value)
		}
	}
	if (picked.length === 0 || (typeof field.maxSelected === 'number' && picked.length > field.maxSelected)) {
		return undefined
	}
	return picked
}

/** Dates and times compare as text in their fixed formats. */
function inRange(field: FormField, raw: string): boolean {
	const min = typeof field.min === 'string' ? field.min : null
	const max = typeof field.max === 'string' ? field.max : null
	return (min === null || raw >= min) && (max === null || raw <= max)
}
