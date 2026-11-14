/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { OPTION_LIST_IDS, optionList } from './optionLists.ts'

const VALUE = /^[a-z][a-z0-9_]{1,31}$/

describe('option lists', () => {
	for (const id of OPTION_LIST_IDS) {
		it(`${id} has valid, unique values and labels, within the option limit`, () => {
			const options = optionList(id, 'en')
			expect(options.length).toBeGreaterThan(3)
			expect(options.length).toBeLessThanOrEqual(300)
			expect(new Set(options.map((o) => o.value)).size).toBe(options.length)
			for (const option of options) {
				expect(option.value).toMatch(VALUE)
				expect(option.label.trim()).not.toBe('')
				expect(option.label.length).toBeLessThanOrEqual(255)
			}
		})
	}

	it('labels countries in the requested language and sorts them', () => {
		const de = optionList('countries', 'de')
		expect(de.find((o) => o.value === 'de')?.label).toBe('Deutschland')
		expect(de.find((o) => o.value === 'us')?.label).toBe('Vereinigte Staaten')
		const en = optionList('countries', 'en').map((o) => o.label)
		expect(en).toEqual([...en].sort((a, b) => a.localeCompare(b, 'en')))
	})

	it('prefixes subdivision codes with their country', () => {
		expect(optionList('usStates', 'en').find((o) => o.label === 'California')?.value).toBe('us_ca')
		expect(optionList('caProvinces', 'en').find((o) => o.label === 'Ontario')?.value).toBe('ca_on')
	})

	it('lists months and weekdays in order', () => {
		expect(optionList('months', 'en').map((o) => o.label)[0]).toBe('January')
		expect(optionList('weekdays', 'en').map((o) => o.label)[0]).toBe('Monday')
	})
})
