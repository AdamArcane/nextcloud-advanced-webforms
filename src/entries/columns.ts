/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Which answers the responses table shows as columns.

import type { VersionDefinition } from '../types.ts'

import { answerFieldOrder, displayAnswer } from './format.ts'

export interface ResponseColumn {
	id: string
	label: string
}

/** How many answer columns the table starts with. More are one tap away in the column picker. */
export const DEFAULT_COLUMN_COUNT = 5

/**
 * Every answer any listed response has, as one column each. Responses can belong to different versions of
 * the form, so this is the union: newest version first, each in its own layout order, a field's label
 * taken from the newest version that has it.
 */
export function responseColumns(versions: Record<string, VersionDefinition>): ResponseColumn[] {
	const columns: ResponseColumn[] = []
	const seen = new Set<string>()
	const ids = Object.keys(versions).sort((a, b) => Number(b) - Number(a))
	for (const versionId of ids) {
		const definition = versions[versionId]
		for (const fieldId of answerFieldOrder(definition)) {
			if (!seen.has(fieldId)) {
				seen.add(fieldId)
				columns.push({ id: fieldId, label: definition.fields[fieldId]?.label || fieldId })
			}
		}
	}
	return columns
}

export function defaultColumnIds(columns: ResponseColumn[]): string[] {
	return columns.slice(0, DEFAULT_COLUMN_COUNT).map((column) => column.id)
}

/** One answer as the table shows it: empty when that version of the form had no such field. */
export function cellText(definition: VersionDefinition, data: Record<string, unknown>, fieldId: string): string {
	const field = definition.fields?.[fieldId]
	return field === undefined ? '' : displayAnswer(field, data[fieldId])
}

const STORAGE_PREFIX = 'webforms.columns.'

/** The columns this person chose for this form, or null when they never chose. */
export function loadColumnChoice(formId: number): string[] | null {
	try {
		const raw = window.localStorage.getItem(STORAGE_PREFIX + formId)
		const parsed: unknown = raw === null ? null : JSON.parse(raw)
		return Array.isArray(parsed) && parsed.every((id) => typeof id === 'string') ? parsed : null
	} catch {
		return null
	}
}

export function saveColumnChoice(formId: number, ids: string[]): void {
	try {
		window.localStorage.setItem(STORAGE_PREFIX + formId, JSON.stringify(ids))
	} catch {
		// Private windows and blocked storage: the choice just lasts until the page is closed.
	}
}

/** The chosen columns that still exist, in the order the form lays them out. */
export function visibleColumns(columns: ResponseColumn[], chosen: string[]): ResponseColumn[] {
	const wanted = new Set(chosen)
	return columns.filter((column) => wanted.has(column.id))
}
