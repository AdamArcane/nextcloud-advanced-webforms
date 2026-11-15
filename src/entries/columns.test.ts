// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { VersionDefinition } from '../types.ts'

import { beforeEach, describe, expect, it } from 'vitest'
import { cellText, defaultColumnIds, loadColumnChoice, responseColumns, saveColumnChoice, visibleColumns } from './columns.ts'

function version(fields: Record<string, { type: string, label: string, options?: { value: string, label: string }[] }>, order: string[]): VersionDefinition {
	return {
		fields: fields as VersionDefinition['fields'],
		pages: [{ id: 'p_1', title: null, rows: order.map((id, i) => ({ id: 'r_' + i, cells: [{ id: 'c_' + i, span: 12, field: id }] })) }],
	} as VersionDefinition
}

const V1 = version({ f_name: { type: 'text', label: 'Name' }, f_old: { type: 'text', label: 'Old question' } }, ['f_name', 'f_old'])
const V2 = version({
	f_name: { type: 'text', label: 'Full name' },
	f_kind: { type: 'select', label: 'Kind', options: [{ value: 'opt_a', label: 'Support' }] },
	f_intro: { type: 'paragraph', label: 'Intro' },
}, ['f_intro', 'f_kind', 'f_name'])

describe('responseColumns', () => {
	it('is the union of the listed versions, newest first, labels from the newest that has the field', () => {
		const columns = responseColumns({ 1: V1, 2: V2 })
		expect(columns.map((column) => column.id)).toEqual(['f_kind', 'f_name', 'f_old'])
		expect(columns.find((column) => column.id === 'f_name')?.label).toBe('Full name')
	})

	it('leaves out headings and paragraphs, and handles no versions', () => {
		expect(responseColumns({ 2: V2 }).map((column) => column.id)).not.toContain('f_intro')
		expect(responseColumns({})).toEqual([])
	})

	it('starts with the first five', () => {
		const many = version(Object.fromEntries(Array.from({ length: 8 }, (_, i) => ['f_a' + i, { type: 'text', label: 'A' + i }])), Array.from({ length: 8 }, (_, i) => 'f_a' + i))
		expect(defaultColumnIds(responseColumns({ 1: many }))).toEqual(['f_a0', 'f_a1', 'f_a2', 'f_a3', 'f_a4'])
	})
})

describe('cellText', () => {
	it('shows an answer as people read it, and nothing for a field that version never had', () => {
		expect(cellText(V2, { f_kind: 'opt_a' }, 'f_kind')).toBe('Support')
		expect(cellText(V2, {}, 'f_kind')).toBe('')
		expect(cellText(V1, { f_kind: 'opt_a' }, 'f_kind')).toBe('')
	})
})

describe('column choice', () => {
	beforeEach(() => {
		window.localStorage.clear()
	})

	it('remembers a choice per form and falls back to none', () => {
		expect(loadColumnChoice(7)).toBeNull()
		saveColumnChoice(7, ['f_name', 'f_kind'])
		expect(loadColumnChoice(7)).toEqual(['f_name', 'f_kind'])
		expect(loadColumnChoice(8)).toBeNull()
	})

	it('ignores damaged storage', () => {
		window.localStorage.setItem('webforms.columns.7', '{nope')
		expect(loadColumnChoice(7)).toBeNull()
		window.localStorage.setItem('webforms.columns.7', '[1, 2]')
		expect(loadColumnChoice(7)).toBeNull()
	})

	it('shows the chosen columns in form order, dropping any that no longer exist', () => {
		const columns = responseColumns({ 1: V1, 2: V2 })
		expect(visibleColumns(columns, ['f_old', 'f_gone', 'f_kind']).map((column) => column.id)).toEqual(['f_kind', 'f_old'])
	})
})
