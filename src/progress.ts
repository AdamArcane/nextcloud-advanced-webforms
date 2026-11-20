/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Keeping a respondent's unsent answers in their own browser (docs/SCHEMA.md section 21).
// Nothing here talks to the server.

import type { FormField } from './types.ts'

import { hasParts } from './answers.ts'
import { isInputType, valueType } from './rules/fieldTypes.ts'

/** Unsent answers are forgotten after this long. */
export const PROGRESS_TTL_MS = 30 * 24 * 3600 * 1000

/** Longest text kept for one answer, the server's limit for long text. */
const MAX_TEXT = 20000

/** Largest record kept for one form, the server's limit for a submission. */
const MAX_RECORD_CHARS = 200 * 1024

const FORMAT = 1

export interface RestoredProgress {
	/** Answers the form as it is now still takes, by field id. */
	values: Record<string, unknown>
	/** The page the respondent was on. The form clamps it to the pages it has. */
	page: number
	/** When the answers were last kept, in milliseconds. */
	savedAt: number
	/** Some answer was left out because its field is gone or no longer takes it. */
	dropped: boolean
}

/** Uploads and signatures are not kept (too large), and a hidden field always comes from the address or its default. */
export function isKept(field: FormField | undefined): field is FormField {
	return field !== undefined && isInputType(field.type) && field.type !== 'file' && field.type !== 'hidden' && field.type !== 'signature'
}

function isBlank(value: unknown): boolean {
	if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
		return !hasParts(value)
	}
	return value === '' || value === false || value === null || value === undefined || (Array.isArray(value) && value.length === 0)
}

/**
 * A kept answer in the shape the field holds answers in, or undefined when the field would not hold
 * it. Only the shape is checked: the server checks everything when the form is sent.
 */
function fitting(field: FormField, value: unknown): unknown {
	switch (valueType(field)) {
		case 'checkbox':
		case 'consent':
			return typeof value === 'boolean' ? value : undefined
		case 'checkboxes': {
			if (!Array.isArray(value)) {
				return undefined
			}
			const known = new Set((field.options ?? []).map((option) => option.value))
			const picked = [...new Set(value)]
			return picked.every((entry) => typeof entry === 'string' && known.has(entry)) ? picked : undefined
		}
		case 'select':
		case 'radio':
			return typeof value === 'string' && (value === '' || (field.options ?? []).some((option) => option.value === value)) ? value : undefined
		case 'matrix':
		case 'daterange':
		case 'name':
		case 'address':
			return typeof value === 'object' && value !== null && !Array.isArray(value) && JSON.stringify(value).length <= MAX_TEXT ? value : undefined
		default:
			return typeof value === 'string' && value.length <= MAX_TEXT ? value : undefined
	}
}

/** The record to keep, as text, or null when it would be too large to keep. */
export function packProgress(fields: Record<string, FormField>, values: Record<string, unknown>, page: number, versionId: number, now: number): string | null {
	const kept: Record<string, unknown> = {}
	for (const [id, field] of Object.entries(fields)) {
		if (isKept(field) && !isBlank(values[id])) {
			kept[id] = values[id]
		}
	}
	const text = JSON.stringify({ format: FORMAT, versionId, savedAt: now, page, values: kept })
	return text.length <= MAX_RECORD_CHARS ? text : null
}

/**
 * Read a kept record against the form as it is now. Null when there is nothing usable: no record, a
 * broken or expired one, or one with no answer the form still takes. A record from an earlier version
 * of the form is used: field ids never change meaning (docs/SCHEMA.md section 4.1), so an answer goes
 * back to its field when the field still takes it and is left out otherwise.
 */
export function restoreProgress(raw: string | null, fields: Record<string, FormField>, now: number): RestoredProgress | null {
	if (raw === null || raw.length > MAX_RECORD_CHARS) {
		return null
	}
	let record: unknown
	try {
		record = JSON.parse(raw)
	} catch {
		return null
	}
	if (typeof record !== 'object' || record === null) {
		return null
	}
	const { format, savedAt, page, values } = record as Record<string, unknown>
	if (format !== FORMAT || typeof savedAt !== 'number' || savedAt > now || now - savedAt > PROGRESS_TTL_MS
		|| typeof values !== 'object' || values === null || Array.isArray(values)) {
		return null
	}
	const restored: Record<string, unknown> = {}
	let dropped = false
	for (const [id, value] of Object.entries(values)) {
		if (isBlank(value)) {
			continue
		}
		const field = Object.hasOwn(fields, id) ? fields[id] : undefined
		const fits = isKept(field) ? fitting(field, value) : undefined
		if (fits === undefined) {
			dropped = true
		} else {
			restored[id] = fits
		}
	}
	if (Object.keys(restored).length === 0) {
		return null
	}
	return {
		values: restored,
		page: typeof page === 'number' && Number.isInteger(page) && page >= 0 ? page : 0,
		savedAt,
		dropped,
	}
}

function key(token: string): string {
	return 'webforms:progress:' + token
}

// The browser can refuse storage (private windows, blocked site data, a full quota). Then the form
// simply works without it.

export function readProgress(token: string): string | null {
	try {
		return window.localStorage.getItem(key(token))
	} catch {
		return null
	}
}

/** Whether the browser took the record. */
export function writeProgress(token: string, record: string): boolean {
	try {
		window.localStorage.setItem(key(token), record)
		return true
	} catch {
		// Not kept this time.
		return false
	}
}

export function clearProgress(token: string): void {
	try {
		window.localStorage.removeItem(key(token))
	} catch {
		// Nothing to clear.
	}
}
