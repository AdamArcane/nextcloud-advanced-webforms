/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { bulkDeleteBody, exportQuery, exportScope } from './exporting.ts'
import { EMPTY_FILTER } from './filter.ts'

describe('exportQuery', () => {
	it('is empty for everything as CSV', () => {
		expect(exportQuery()).toBe('')
		expect(exportQuery({ filter: EMPTY_FILTER })).toBe('')
	})

	it('asks for Excel, and for chosen columns', () => {
		expect(exportQuery({ format: 'xlsx', columns: ['f_name', 'f_kind'] })).toBe('format=xlsx&columns=f_name%2Cf_kind')
		expect(exportQuery({ format: 'csv' })).toBe('')
	})

	it('carries the filter, but not when ticked responses are given', () => {
		const filter = { ...EMPTY_FILTER, text: 'ada', respondent: 'anonymous' as const }
		expect(exportQuery({ filter })).toBe('q=ada&respondent=anonymous')
		expect(exportQuery({ filter, ids: [3, 5] })).toBe('ids=3%2C5')
	})

	it('treats an empty selection as no selection', () => {
		expect(exportQuery({ ids: [], filter: { ...EMPTY_FILTER, text: 'x' } })).toBe('q=x')
	})

	it('keeps a chosen set of no columns, which means only the fixed ones', () => {
		expect(exportQuery({ columns: [] })).toBe('columns=')
	})
})

describe('bulkDeleteBody', () => {
	it('sends ticked ids as they are', () => {
		expect(bulkDeleteBody({ ids: [1, 2] })).toEqual({ ids: [1, 2] })
	})

	it('sends a filter with the confirmed count, without the sort order', () => {
		const filter = { ...EMPTY_FILTER, text: 'ada', sort: 'oldest' as const }
		expect(bulkDeleteBody({ filter, expected: 12 })).toEqual({ filter: { q: 'ada' }, expected: 12 })
		expect(bulkDeleteBody({ filter: EMPTY_FILTER, expected: 0 })).toEqual({ filter: {}, expected: 0 })
	})
})

describe('exportScope', () => {
	it('is what the person sees: ticked, matching, or everything', () => {
		expect(exportScope(3, false, true)).toBe('selected')
		expect(exportScope(0, false, true)).toBe('matching')
		expect(exportScope(0, false, false)).toBe('all')
		// "Select all matching" means the filter, however many are loaded.
		expect(exportScope(40, true, true)).toBe('matching')
		expect(exportScope(40, true, false)).toBe('matching')
	})
})
