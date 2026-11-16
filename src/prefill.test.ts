/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormField } from './types.ts'

import { describe, expect, it } from 'vitest'
import { prefillValue } from './prefill.ts'

const options = [{ value: 'news', label: 'Newsletter' }, { value: 'ad', label: 'An advert' }, { value: 'friend', label: 'A friend' }]

function value(field: FormField, query: string): unknown {
	return prefillValue('p', field, new URLSearchParams(query))
}

describe('prefillValue', () => {
	it('reads the parameter named by the field id, and never fills uploads', () => {
		expect(prefillValue('f_name', { type: 'text' }, new URLSearchParams('f_name=Ada&p=x'))).toBe('Ada')
		expect(value({ type: 'text' }, 'other=x')).toBeUndefined()
		expect(value({ type: 'file' }, 'p=x')).toBeUndefined()
	})

	it('takes text as it is, within the field limits', () => {
		expect(value({ type: 'hidden' }, 'p=%20newsletter%20')).toBe('newsletter')
		expect(value({ type: 'text' }, 'p=Ada+Lovelace')).toBe('Ada Lovelace')
		expect(value({ type: 'text', maxLength: 3 }, 'p=abcd')).toBeUndefined()
		expect(value({ type: 'text', minLength: 5 }, 'p=abc')).toBeUndefined()
		expect(value({ type: 'hidden' }, 'p=' + 'x'.repeat(2001))).toBeUndefined()
		expect(value({ type: 'textarea' }, 'p=' + 'x'.repeat(2001))).toBe('x'.repeat(2001))
		expect(value({ type: 'text' }, 'p=')).toBeUndefined()
	})

	it('checks email, phone and web address', () => {
		expect(value({ type: 'email' }, 'p=ada%40example.com')).toBe('ada@example.com')
		expect(value({ type: 'email' }, 'p=nope')).toBeUndefined()
		expect(value({ type: 'phone' }, 'p=%2B1%20555%20123')).toBe('+1 555 123')
		expect(value({ type: 'phone' }, 'p=call+me')).toBeUndefined()
		expect(value({ type: 'url' }, 'p=https%3A%2F%2Fexample.com')).toBe('https://example.com')
		expect(value({ type: 'url' }, 'p=example.com')).toBeUndefined()
	})

	it('takes a number inside its limits and steps', () => {
		expect(value({ type: 'number' }, 'p=-2.5')).toBe('-2.5')
		expect(value({ type: 'number' }, 'p=12abc')).toBeUndefined()
		expect(value({ type: 'number', min: 1, max: 10 }, 'p=11')).toBeUndefined()
		expect(value({ type: 'number', step: 5 }, 'p=12')).toBeUndefined()
		expect(value({ type: 'number', step: 5 }, 'p=15')).toBe('15')
	})

	it('matches a choice by value or by label, ignoring case', () => {
		expect(value({ type: 'select', options }, 'p=news')).toBe('news')
		expect(value({ type: 'radio', options }, 'p=an+ADVERT')).toBe('ad')
		expect(value({ type: 'select', options }, 'p=billboard')).toBeUndefined()
	})

	it('takes several choices, repeated or comma separated, leaving out unknown ones', () => {
		expect(value({ type: 'checkboxes', options }, 'p=news,friend')).toEqual(['news', 'friend'])
		expect(value({ type: 'checkboxes', options }, 'p=news&p=A+friend&p=billboard&p=news')).toEqual(['news', 'friend'])
		expect(value({ type: 'checkboxes', options }, 'p=billboard')).toBeUndefined()
		expect(value({ type: 'checkboxes', options, maxSelected: 1 }, 'p=news,ad')).toBeUndefined()
	})

	it('reads a checkbox from yes and no words', () => {
		for (const word of ['1', 'true', 'YES', 'on']) {
			expect(value({ type: 'checkbox' }, 'p=' + word)).toBe(true)
		}
		for (const word of ['0', 'false', 'no', 'Off']) {
			expect(value({ type: 'checkbox' }, 'p=' + word)).toBe(false)
		}
		expect(value({ type: 'checkbox' }, 'p=maybe')).toBeUndefined()
	})

	it('takes dates and times in their formats and limits', () => {
		expect(value({ type: 'date' }, 'p=2026-12-01')).toBe('2026-12-01')
		expect(value({ type: 'date' }, 'p=1%2F12%2F2026')).toBeUndefined()
		expect(value({ type: 'date', min: '2027-01-01' }, 'p=2026-12-01')).toBeUndefined()
		expect(value({ type: 'time' }, 'p=09%3A30')).toBe('09:30')
		expect(value({ type: 'time', max: '09:00' }, 'p=09:30')).toBeUndefined()
	})
})
