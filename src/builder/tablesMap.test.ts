/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ColumnKind, TablesColumn } from './tablesMap.ts'
import type { DraftDefinition } from '../types.ts'

import { describe, expect, it } from 'vitest'
import { addField, emptyDefinition } from './definition.ts'
import { addAction } from './actions.ts'
import { brokenMappings, compatible, META_ENTRY, META_RESPONDENT, META_SUBMITTED, setMapping, sourcesForColumn, suggestMap, unmatchedOptions } from './tablesMap.ts'

function column(id: number, title: string, kind: ColumnKind | null, extra: Partial<TablesColumn> = {}): TablesColumn {
	return { id, title, type: 'text', subtype: '', mandatory: false, options: [], kind, supported: kind !== null, ...extra }
}

function form(): { def: DraftDefinition, ids: Record<string, string> } {
	let def = emptyDefinition('T')
	const ids: Record<string, string> = {}
	let index = 0
	for (const [key, type, label] of [['name', 'text', 'Full Name'], ['kind', 'select', 'Kind'], ['size', 'number', 'Size'], ['agree', 'checkbox', 'Agree'], ['when', 'date', 'Date']] as const) {
		const added = addField(def, type, { kind: 'row', index: index++ })
		def = { ...added.definition, fields: { ...added.definition.fields, [added.fieldId]: { ...added.definition.fields[added.fieldId], label } } }
		ids[key] = added.fieldId
	}
	return { def, ids }
}

describe('compatible (mirrors TablesValue::compatible)', () => {
	it('lets a text column take any answer and meta value', () => {
		for (const source of ['text', 'email', 'select', 'checkboxes', 'file', META_SUBMITTED, META_RESPONDENT, META_ENTRY]) {
			expect(compatible(source, 'text')).toBe(true)
		}
	})

	it('matches the other kinds to the field types that fit', () => {
		expect(compatible('number', 'number')).toBe(true)
		expect(compatible('text', 'number')).toBe(false)
		expect(compatible(META_ENTRY, 'number')).toBe(true)
		expect(compatible(META_SUBMITTED, 'number')).toBe(false)
		expect(compatible('select', 'single')).toBe(true)
		expect(compatible('radio', 'single')).toBe(true)
		expect(compatible('checkboxes', 'single')).toBe(false)
		expect(compatible('checkboxes', 'multi')).toBe(true)
		expect(compatible('checkbox', 'check')).toBe(true)
		expect(compatible('date', 'date')).toBe(true)
		expect(compatible('date', 'datetime')).toBe(true)
		expect(compatible('time', 'date')).toBe(false)
		expect(compatible(META_SUBMITTED, 'time')).toBe(true)
	})

	it('never fills a column of an unsupported type', () => {
		expect(compatible('text', null)).toBe(false)
		expect(compatible(META_RESPONDENT, null)).toBe(false)
	})
})

describe('sourcesForColumn', () => {
	it('offers the fitting fields first, then the automatic values', () => {
		const { def, ids } = form()
		expect(sourcesForColumn(def, column(1, 'When', 'datetime')).map((option) => option.id)).toEqual([ids.when, META_SUBMITTED])
		expect(sourcesForColumn(def, column(2, 'Count', 'number')).map((option) => option.id)).toEqual([ids.size, META_ENTRY])
		expect(sourcesForColumn(def, column(3, 'Owner', null))).toEqual([])
	})
})

describe('suggestMap', () => {
	it('matches column titles to field labels, ignoring case and spacing', () => {
		const { def, ids } = form()
		const columns = [column(1, ' full  name ', 'text'), column(2, 'KIND', 'single'), column(3, 'Notes', 'text')]
		expect(suggestMap(def, columns, {})).toEqual({ 1: ids.name, 2: ids.kind })
	})

	it('only suggests answers the column can take', () => {
		const { def } = form()
		// A field called "Size" is a number field, so a text-only match is fine but a "single" column is not.
		expect(suggestMap(def, [column(1, 'Size', 'single')], {})).toEqual({})
	})

	it('never changes an existing choice or uses a field twice', () => {
		const { def, ids } = form()
		const columns = [column(1, 'Full Name', 'text'), column(2, 'Full Name', 'text')]
		expect(suggestMap(def, columns, { 1: ids.kind })).toEqual({ 1: ids.kind, 2: ids.name })
		expect(suggestMap(def, [column(5, 'Full Name', 'text'), column(6, 'Full Name', 'text')], {})).toEqual({ 5: ids.name })
	})

	it('skips columns that cannot be filled', () => {
		const { def } = form()
		expect(suggestMap(def, [column(1, 'Full Name', null)], {})).toEqual({})
	})
})

describe('setMapping and brokenMappings', () => {
	it('sets and clears one column', () => {
		expect(setMapping({ 1: 'a' }, 2, 'b')).toEqual({ 1: 'a', 2: 'b' })
		expect(setMapping({ 1: 'a', 2: 'b' }, 1, null)).toEqual({ 2: 'b' })
	})

	it('flags columns that are gone, or whose answer no longer fits', () => {
		const { def, ids } = form()
		const columns = [column(1, 'Name', 'text'), column(2, 'Count', 'number')]
		expect(brokenMappings(def, columns, { 1: ids.name, 2: ids.size })).toEqual([])
		expect(brokenMappings(def, columns, { 1: ids.name, 2: ids.name })).toEqual(['2'])
		expect(brokenMappings(def, columns, { 9: ids.name })).toEqual(['9'])
		expect(brokenMappings(def, columns, { 1: 'f_deleted' })).toEqual(['1'])
	})
})

describe('unmatchedOptions', () => {
	it('lists the choices the column has no option for, ignoring case and spacing', () => {
		const { def, ids } = form()
		def.fields[ids.kind] = { ...def.fields[ids.kind], options: [{ value: 'opt_a', label: 'Support' }, { value: 'opt_b', label: ' sales  team ' }, { value: 'opt_c', label: 'Other' }] } as DraftDefinition['fields'][string]
		const single = column(1, 'Kind', 'single', { options: [{ id: 1, label: 'support' }, { id: 2, label: 'Sales Team' }] })
		expect(unmatchedOptions(def, single, ids.kind)).toEqual(['Other'])
		const multi = column(2, 'Kinds', 'multi', { options: [{ id: 1, label: 'Support' }] })
		expect(unmatchedOptions(def, multi, ids.kind)).toEqual(['sales  team', 'Other'])
	})

	it('is empty when everything matches, nothing is mapped, or it is not a choice mapping', () => {
		const { def, ids } = form()
		def.fields[ids.kind] = { ...def.fields[ids.kind], options: [{ value: 'opt_a', label: 'Support' }] } as DraftDefinition['fields'][string]
		const single = column(1, 'Kind', 'single', { options: [{ id: 1, label: 'Support' }] })
		expect(unmatchedOptions(def, single, ids.kind)).toEqual([])
		expect(unmatchedOptions(def, single, undefined)).toEqual([])
		expect(unmatchedOptions(def, column(2, 'Notes', 'text'), ids.kind)).toEqual([])
		expect(unmatchedOptions(def, single, ids.name)).toEqual([])
		expect(unmatchedOptions(def, single, META_ENTRY)).toEqual([])
	})
})

describe('addAction for tables', () => {
	it('starts with no table and an empty mapping', () => {
		const { def } = form()
		const action = addAction(def, 'tables').definition.actions[0]
		expect(action).toMatchObject({ type: 'tables', tableId: 0, map: {} })
	})
})
