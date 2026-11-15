/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ChoiceSummary, YesNoSummary } from './summary.ts'

import { describe, expect, it } from 'vitest'
import { barRows, bucketLabel, columnHeights, formatNumber } from './summary.ts'

const choice: ChoiceSummary = {
	id: 'f_kind', label: 'Kind', type: 'select', kind: 'choice', answered: 8,
	options: [{ value: 'a', label: 'Support', count: 2 }, { value: 'b', label: 'Sales', count: 6 }, { value: 'c', label: 'Other', count: 0 }],
}

describe('barRows', () => {
	it('keeps the form order, gives the share of people who answered, and sizes bars against the longest', () => {
		expect(barRows(choice)).toEqual([
			{ key: 'a', label: 'Support', count: 2, percent: 25, width: (2 / 6) * 100 },
			{ key: 'b', label: 'Sales', count: 6, percent: 75, width: 100 },
			{ key: 'c', label: 'Other', count: 0, percent: 0, width: 0 },
		])
	})

	it('lets checkbox shares add up to more than 100, since people pick several', () => {
		const boxes: ChoiceSummary = { ...choice, type: 'checkboxes', answered: 4, options: [{ value: 'a', label: 'A', count: 4 }, { value: 'b', label: 'B', count: 3 }] }
		expect(barRows(boxes).map((row) => row.percent)).toEqual([100, 75])
	})

	it('shows a yes or no question as two bars', () => {
		const yesNo: YesNoSummary = { id: 'f_ok', label: 'Agree', type: 'checkbox', kind: 'yesno', answered: 4, yes: 3, no: 1 }
		expect(barRows(yesNo).map((row) => [row.label, row.count, row.percent])).toEqual([['Yes', 3, 75], ['No', 1, 25]])
	})

	it('copes with nobody having answered', () => {
		expect(barRows({ ...choice, answered: 0, options: [{ value: 'a', label: 'A', count: 0 }] })).toEqual([{ key: 'a', label: 'A', count: 0, percent: 0, width: 0 }])
	})
})

describe('timeline helpers', () => {
	it('names a day or a month, in UTC', () => {
		expect(bucketLabel({ start: '2026-11-01', count: 1 }, 'day', 'en-US')).toBe('Nov 1, 2026')
		expect(bucketLabel({ start: '2026-11-01', count: 1 }, 'month', 'en-US')).toBe('November 2026')
	})

	it('scales columns to the tallest, keeps empty days empty and small days visible', () => {
		expect(columnHeights([{ start: 'a', count: 0 }, { start: 'b', count: 1 }, { start: 'c', count: 100 }])).toEqual([0, 4, 100])
		expect(columnHeights([])).toEqual([])
	})

	it('formats numbers briefly', () => {
		expect(formatNumber(3.5263, 'en-US')).toBe('3.53')
		expect(formatNumber(4, 'en-US')).toBe('4')
		expect(formatNumber(null)).toBe('—')
	})
})
