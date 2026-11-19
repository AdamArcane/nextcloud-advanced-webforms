/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { NormalizedValue } from './types.ts'

// Mirrors lib/Rules/ValueNormalizer.php. Both must behave identically
// (docs/SCHEMA.md section 6.2); tests/vectors/rules.json guards that.

const NUMBER_PATTERN = /^-?\d+(\.\d+)?$/
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/
const TIME_PATTERN = /^\d{2}:\d{2}$/
const COLOR_PATTERN = /^#[0-9a-fA-F]{6}$/
const DATETIME_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/

/**
 * Turn a raw submitted value, or a rule's value, into its comparison form.
 * null means "empty".
 */
export function normalize(type: string, raw: unknown): NormalizedValue {
	switch (type) {
		case 'text':
		case 'textarea':
		case 'email':
		case 'url':
		case 'hidden':
			return normalizeText(raw)
		case 'phone':
			return normalizePhone(raw)
		case 'number':
		case 'rating':
		case 'scale':
		case 'slider':
			return normalizeNumber(raw)
		case 'color':
			return typeof raw === 'string' && COLOR_PATTERN.test(raw) ? raw.toLowerCase() : null
		case 'signature':
			return typeof raw === 'string' && raw.startsWith('data:image/png;base64,') ? true : null
		case 'matrix':
		case 'daterange':
		case 'name':
		case 'address':
			// Only whether anything was answered matters to a rule.
			return typeof raw === 'object' && raw !== null && !Array.isArray(raw) && Object.keys(raw).length > 0 ? true : null
		case 'select':
		case 'radio':
			return typeof raw === 'string' && raw !== '' ? raw : null
		case 'checkboxes':
		case 'file':
			return normalizeList(raw)
		case 'checkbox':
		case 'consent':
			return typeof raw === 'boolean' ? raw : null
		case 'datetime':
			return typeof raw === 'string' && DATETIME_PATTERN.test(raw) ? raw : null
		case 'date':
			return typeof raw === 'string' && DATE_PATTERN.test(raw) ? raw : null
		case 'time':
			return typeof raw === 'string' && TIME_PATTERN.test(raw) ? raw : null
		default:
			return null
	}
}

function normalizeText(raw: unknown): string | null {
	if (typeof raw !== 'string') {
		return null
	}
	const trimmed = raw.trim()
	return trimmed === '' ? null : trimmed.toLowerCase()
}

function normalizePhone(raw: unknown): string | null {
	if (typeof raw !== 'string') {
		return null
	}
	const trimmed = raw.trim()
	const digits = trimmed.replace(/\D+/g, '')
	if (digits === '') {
		return null
	}
	return (trimmed.startsWith('+') ? '+' : '') + digits
}

function normalizeNumber(raw: unknown): number | null {
	if (typeof raw === 'number') {
		return Number.isFinite(raw) ? raw : null
	}
	if (typeof raw === 'string') {
		const trimmed = raw.trim()
		if (NUMBER_PATTERN.test(trimmed)) {
			return Number(trimmed)
		}
	}
	return null
}

function normalizeList(raw: unknown): string[] | null {
	if (!Array.isArray(raw) || raw.length === 0) {
		return null
	}
	if (!raw.every((item) => typeof item === 'string')) {
		return null
	}
	return raw as string[]
}
