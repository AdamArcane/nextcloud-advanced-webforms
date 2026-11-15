/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Pure helpers for the Tables action's column mapping (docs/SCHEMA.md section 15). The server
// decides what is allowed (TablesValue::compatible), and this mirrors it so the screen only offers
// answers a column can take.

import type { DraftDefinition } from '../types.ts'

import { t } from '@nextcloud/l10n'
import { sourceFields } from './rules.ts'

export interface TablesColumn {
	id: number
	title: string
	type: string
	subtype: string
	mandatory: boolean
	options: { id: number, label: string }[]
	/** What the column holds, or null for a type a form cannot fill (people, relations). */
	kind: ColumnKind | null
	supported: boolean
}

export interface TablesList {
	available: boolean
	tables: { id: number, title: string, emoji: string }[]
}

export type ColumnKind = 'text' | 'number' | 'stars' | 'progress' | 'single' | 'multi' | 'check' | 'datetime' | 'date' | 'time'

export const META_SUBMITTED = '@submitted'
export const META_RESPONDENT = '@respondent'
export const META_ENTRY = '@entry'
export const META_SOURCES = [META_SUBMITTED, META_RESPONDENT, META_ENTRY] as const

export interface SourceOption {
	id: string
	label: string
	/** What it is, for the picker: a field's type or "Automatic". */
	hint: string
}

/** Whether an answer of this field type (or meta source) can go into a column of this kind. */
export function compatible(source: string, kind: ColumnKind | null): boolean {
	if (kind === null) {
		return false
	}
	if ((META_SOURCES as readonly string[]).includes(source)) {
		switch (kind) {
			case 'text': return true
			case 'number':
			case 'stars':
			case 'progress': return source === META_ENTRY
			case 'datetime':
			case 'date':
			case 'time': return source === META_SUBMITTED
			default: return false
		}
	}
	switch (kind) {
		case 'text': return true
		case 'number':
		case 'stars':
		case 'progress': return source === 'number'
		case 'single': return source === 'select' || source === 'radio'
		case 'multi': return source === 'checkboxes'
		case 'check': return source === 'checkbox'
		case 'date':
		case 'datetime': return source === 'date'
		case 'time': return source === 'time'
		default: return false
	}
}

export function metaLabel(source: string): string {
	switch (source) {
		case META_SUBMITTED: return t('webforms', 'When it was submitted')
		case META_RESPONDENT: return t('webforms', 'Who submitted it (their account)')
		default: return t('webforms', 'Response number')
	}
}

/** The answers a column can take: the form's fields that fit, then the automatic values that fit. */
export function sourcesForColumn(definition: DraftDefinition, column: TablesColumn): SourceOption[] {
	const options: SourceOption[] = sourceFields(definition)
		.filter(({ field }) => compatible(field.type, column.kind))
		.map(({ id, field }) => ({ id, label: field.label || id, hint: field.type }))
	for (const source of META_SOURCES) {
		if (compatible(source, column.kind)) {
			options.push({ id: source, label: metaLabel(source), hint: t('webforms', 'Automatic') })
		}
	}
	return options
}

/** What a mapped source is called, for the summary line. A source that no longer exists reads as its id. */
export function sourceLabel(definition: DraftDefinition, source: string): string {
	if ((META_SOURCES as readonly string[]).includes(source)) {
		return metaLabel(source)
	}
	return definition.fields[source]?.label || source
}

/** Set one column's source, or clear it with null. */
export function setMapping(map: Record<string, string>, columnId: number, source: string | null): Record<string, string> {
	const { [String(columnId)]: _previous, ...rest } = map
	return source === null ? rest : { ...rest, [String(columnId)]: source }
}

/**
 * Fill in columns that are not mapped yet by matching a column's title to a field's label (ignoring
 * case and spacing), using only answers the column can take and each field at most once. Existing
 * choices are never changed.
 */
export function suggestMap(definition: DraftDefinition, columns: TablesColumn[], existing: Record<string, string>): Record<string, string> {
	const map = { ...existing }
	const used = new Set(Object.values(existing))
	const normal = (text: string): string => text.trim().toLowerCase().replace(/\s+/g, ' ')
	for (const column of columns) {
		if (!column.supported || String(column.id) in map) {
			continue
		}
		const match = sourceFields(definition).find(({ id, field }) => !used.has(id)
			&& compatible(field.type, column.kind)
			&& normal(field.label || '') === normal(column.title))
		if (match) {
			map[String(column.id)] = match.id
			used.add(match.id)
		}
	}
	return map
}

/** Mapped columns that are no longer in the table, or no longer take their answer. */
export function brokenMappings(definition: DraftDefinition, columns: TablesColumn[], map: Record<string, string>): string[] {
	const broken: string[] = []
	for (const [columnId, source] of Object.entries(map)) {
		const column = columns.find((candidate) => String(candidate.id) === columnId)
		const type = (META_SOURCES as readonly string[]).includes(source) ? source : definition.fields[source]?.type
		if (!column || type === undefined || !compatible(type, column.kind)) {
			broken.push(columnId)
		}
	}
	return broken
}
