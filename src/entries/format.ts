/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// How a stored response is shown. Mirrors lib/Actions/Template.php (display),
// so the page, emails and the CSV all read an answer the same way.

import type { FormField, StoredFile, VersionDefinition } from '../types.ts'

import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { isInputType } from '../rules/fieldTypes.ts'

function optionLabel(field: FormField, value: unknown): string {
	const raw = typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean' ? String(value) : ''
	return field.options?.find((option) => option.value === raw)?.label ?? raw
}

/** An answer as people expect to read it: option labels, Yes/No, lists joined with commas, nothing for a missing one. */
export function displayAnswer(field: FormField, value: unknown): string {
	if (value === null || value === undefined) {
		return ''
	}
	if (typeof value === 'boolean') {
		return value ? t('webforms', 'Yes') : t('webforms', 'No')
	}
	if (field.type === 'file') {
		return storedFiles(value).map((file) => file.name).join(', ')
	}
	if (Array.isArray(value)) {
		return value.map((item) => optionLabel(field, item)).join(', ')
	}
	if (typeof value === 'number') {
		return String(value)
	}
	if (field.type === 'datetime' && typeof value === 'string') {
		// Stored with a T between the date and the time; people read a space.
		return value.replace('T', ' ')
	}
	return optionLabel(field, value)
}

/** The files of an upload answer; anything that is not a stored file is ignored. */
export function storedFiles(value: unknown): StoredFile[] {
	return Array.isArray(value)
		? value.filter((item): item is StoredFile => typeof item === 'object' && item !== null && typeof (item as StoredFile).name === 'string')
		: []
}

/** Where the owner downloads one file of a response. */
export function entryFileUrl(formId: number, entryId: number, fieldId: string, index: number): string {
	return generateUrl('/apps/webforms/api/v1/forms/{formId}/entries/{entryId}/files/{fieldId}/{index}', { formId, entryId, fieldId, index })
}

/** Input field ids as laid out on that version of the form, top to bottom, then any not placed. */
export function answerFieldOrder(definition: VersionDefinition): string[] {
	const ordered: string[] = []
	for (const row of definition.pages?.[0]?.rows ?? []) {
		for (const cell of row.cells) {
			ordered.push(cell.field)
		}
	}
	ordered.push(...Object.keys(definition.fields ?? {}))
	return [...new Set(ordered)].filter((id) => {
		const field = definition.fields?.[id]
		return field !== undefined && isInputType(field.type)
	})
}

export interface AnswerLine {
	id: string
	label: string
	value: string
	answered: boolean
	/** Upload fields: the files, so the page can link to them. Empty for other fields. */
	files: StoredFile[]
}

/** Every question of the response's own version with its answer, for the detail view. */
export function answerLines(definition: VersionDefinition, data: Record<string, unknown>): AnswerLine[] {
	return answerFieldOrder(definition).map((id) => {
		const field = definition.fields[id]
		const value = displayAnswer(field, data[id])
		return { id, label: field.label || id, value, answered: value !== '', files: field.type === 'file' ? storedFiles(data[id]) : [] }
	})
}

/** The first few answers, for a one-line preview. */
export function previewLines(definition: VersionDefinition, data: Record<string, unknown>, max = 2): AnswerLine[] {
	return answerLines(definition, data).filter((line) => line.answered).slice(0, max)
}

export function formatTime(seconds: number): string {
	return new Date(seconds * 1000).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

/** "in 5 minutes" style text for a time a few minutes to hours ahead. */
export function inFuture(seconds: number, now: number = Math.floor(Date.now() / 1000)): string {
	const delta = Math.max(0, seconds - now)
	if (delta === 0) {
		// Due now: it goes out the next time background jobs run.
		return t('webforms', 'any moment now')
	}
	if (delta < 90) {
		return t('webforms', 'in about a minute')
	}
	const minutes = Math.round(delta / 60)
	return minutes < 90
		? t('webforms', 'in {n} minutes', { n: minutes })
		: t('webforms', 'in {n} hours', { n: Math.round(minutes / 60) })
}
