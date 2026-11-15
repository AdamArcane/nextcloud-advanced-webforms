/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { AllCondition } from '../rules/types.ts'

import { describe, expect, it } from 'vitest'
import { EMPTY_FILTER, filterParams, isFilterActive, rangeInvalid } from './filter.ts'

describe('filterParams', () => {
	it('sends nothing for an empty filter', () => {
		expect(filterParams(EMPTY_FILTER)).toEqual({})
		expect(isFilterActive(EMPTY_FILTER)).toBe(false)
	})

	it('sends only what is set, with the text trimmed and the condition as JSON', () => {
		const where: AllCondition = { all: [{ field: 'f_kind', op: 'eq', value: 'opt_sup' }] }
		const filter = { ...EMPTY_FILTER, text: '  ada ', from: '2026-11-01', to: '2026-11-14', respondent: 'anonymous' as const, where, sort: 'oldest' as const }
		expect(filterParams(filter)).toEqual({
			q: 'ada',
			from: '2026-11-01',
			to: '2026-11-14',
			respondent: 'anonymous',
			where: JSON.stringify(where),
			sort: 'oldest',
		})
		expect(isFilterActive(filter)).toBe(true)
	})

	it('does not count the order, or blank text, as filtering', () => {
		expect(isFilterActive({ ...EMPTY_FILTER, sort: 'oldest' })).toBe(false)
		expect(isFilterActive({ ...EMPTY_FILTER, text: '   ' })).toBe(false)
		expect(filterParams({ ...EMPTY_FILTER, text: '   ' })).toEqual({})
	})
})

describe('rangeInvalid', () => {
	it('flags an end before the start, and only when both are set', () => {
		expect(rangeInvalid({ ...EMPTY_FILTER, from: '2026-11-15', to: '2026-11-14' })).toBe(true)
		expect(rangeInvalid({ ...EMPTY_FILTER, from: '2026-11-14', to: '2026-11-14' })).toBe(false)
		expect(rangeInvalid({ ...EMPTY_FILTER, from: '2026-11-14' })).toBe(false)
	})
})
