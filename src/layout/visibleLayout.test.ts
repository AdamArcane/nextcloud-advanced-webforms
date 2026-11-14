/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LayoutRow } from './visibleLayout.ts'

import { describe, expect, it } from 'vitest'
import { visibleLayout } from './visibleLayout.ts'

const pages = [{
	id: 'p_1',
	title: null,
	rows: [
		{ id: 'r_1', cells: [{ id: 'c_1', span: 6, field: 'f_a' }, { id: 'c_2', span: 6, field: 'f_b' }] },
		{ id: 'r_2', cells: [{ id: 'c_3', span: 4, field: 'f_c' }, { id: 'c_4', span: 8, field: 'f_d' }] },
		{ id: 'r_3', cells: [{ id: 'c_5', span: 12, field: 'f_e' }] },
	],
}]

const fieldsOf = (rows: LayoutRow[]) => rows.map((row) => row.cells.map((cell) => cell.field))

describe('visibleLayout', () => {
	it('keeps everything when all fields are visible', () => {
		const rows = visibleLayout(pages, new Set(['f_a', 'f_b', 'f_c', 'f_d', 'f_e']))
		expect(fieldsOf(rows)).toEqual([['f_a', 'f_b'], ['f_c', 'f_d'], ['f_e']])
	})

	it('removes hidden cells but keeps the survivors spans', () => {
		const rows = visibleLayout(pages, new Set(['f_a', 'f_c', 'f_d', 'f_e']))
		expect(fieldsOf(rows)).toEqual([['f_a'], ['f_c', 'f_d'], ['f_e']])
		expect(rows[0].cells[0].span).toBe(6)
	})

	it('removes a row whose cells are all hidden', () => {
		const rows = visibleLayout(pages, new Set(['f_a', 'f_b', 'f_e']))
		expect(fieldsOf(rows)).toEqual([['f_a', 'f_b'], ['f_e']])
	})

	it('handles an empty form', () => {
		expect(visibleLayout(pages, new Set())).toEqual([])
		expect(visibleLayout([], new Set(['f_a']))).toEqual([])
	})
})
