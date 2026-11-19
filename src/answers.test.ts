/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { addDays, hasParts, isComplete, startingParts } from './answers.ts'
import { displayAnswer, signatureImage } from './entries/format.ts'
import { isUnanswered } from './review.ts'
import { partsOf } from './rules/fieldTypes.ts'
import type { FormField } from './types.ts'

const matrix: FormField = {
	type: 'matrix',
	label: 'Rate',
	questions: [{ value: 'food', label: 'Food' }, { value: 'staff', label: 'Staff' }],
	options: [{ value: 'good', label: 'Good' }, { value: 'bad', label: 'Bad' }],
}

describe('parts of a name and an address', () => {
	it('uses first and last unless told otherwise', () => {
		expect(partsOf({ type: 'name' })).toEqual(['first', 'last'])
		expect(partsOf({ type: 'name', parts: ['last', 'middle', 'first'] })).toEqual(['first', 'middle', 'last'])
		expect(partsOf({ type: 'address' })).toEqual(['street', 'street2', 'city', 'region', 'postal', 'country'])
		expect(partsOf({ type: 'address', parts: ['city', 'first'] })).toEqual(['city'])
	})
})

describe('starting answers', () => {
	it('start empty, one entry per part', () => {
		expect(startingParts({ type: 'name' })).toEqual({ first: '', last: '' })
		expect(startingParts({ type: 'daterange' })).toEqual({ start: '', end: '' })
		expect(startingParts(matrix)).toEqual({})
	})

	it('come from a default object when there is one', () => {
		expect(startingParts({ type: 'name', defaultValue: { first: 'Ada' } })).toEqual({ first: 'Ada' })
	})
})

describe('answered and complete', () => {
	it('an object of empty parts is unanswered', () => {
		expect(hasParts({ first: '', last: ' ' })).toBe(false)
		expect(isUnanswered({ type: 'name' }, { first: '', last: '' })).toBe(true)
		expect(isUnanswered({ type: 'name' }, { first: 'Ada', last: '' })).toBe(false)
		expect(isUnanswered(matrix, { food: [] })).toBe(true)
		expect(isUnanswered(matrix, { food: ['good'] })).toBe(false)
	})

	it('a required name needs its first and last part', () => {
		expect(isComplete({ type: 'name' }, { first: 'Ada', last: '' })).toBe(false)
		expect(isComplete({ type: 'name' }, { first: 'Ada', last: 'L' })).toBe(true)
		expect(isComplete({ type: 'name', parts: ['first'] }, { first: 'Ada' })).toBe(true)
	})

	it('a required address needs street, city, postal code and country', () => {
		expect(isComplete({ type: 'address' }, { street: 'Main 1', city: 'Oslo' })).toBe(false)
		expect(isComplete({ type: 'address' }, { street: 'Main 1', city: 'Oslo', postal: '1', country: 'NO' })).toBe(true)
		expect(isComplete({ type: 'address', parts: ['street', 'city'] }, { street: 'Main 1', city: 'Oslo' })).toBe(true)
	})

	it('a required matrix needs every row, a date range both dates', () => {
		expect(isComplete(matrix, { food: 'good' })).toBe(false)
		expect(isComplete(matrix, { food: 'good', staff: 'bad' })).toBe(true)
		expect(isComplete({ type: 'daterange' }, { start: '2026-01-01', end: '' })).toBe(false)
		expect(isComplete({ type: 'daterange' }, { start: '2026-01-01', end: '2026-01-02' })).toBe(true)
	})
})

describe('how answers read', () => {
	it('reads a matrix by labels', () => {
		expect(displayAnswer(matrix, { food: 'good', staff: 'bad' })).toBe('Food: Good; Staff: Bad')
		expect(displayAnswer({ ...matrix, multiple: true }, { food: ['good', 'bad'] })).toBe('Food: Good, Bad')
	})

	it('reads a name on one line and an address in commas', () => {
		expect(displayAnswer({ type: 'name' }, { first: 'Ada', last: 'Lovelace' })).toBe('Ada Lovelace')
		expect(displayAnswer({ type: 'address' }, { street: 'Main 1', city: 'Oslo' })).toBe('Main 1, Oslo')
	})

	it('reads a date range with "to"', () => {
		expect(displayAnswer({ type: 'daterange' }, { start: '2026-01-01', end: '2026-01-05' })).toBe('2026-01-01 to 2026-01-05')
	})

	it('only shows a stored PNG as a picture', () => {
		const field: FormField = { type: 'signature' }
		expect(signatureImage(field, 'data:image/png;base64,AAAA')).toBe('data:image/png;base64,AAAA')
		expect(signatureImage(field, 'data:text/html;base64,AAAA')).toBeNull()
		expect(signatureImage(field, 'javascript:alert(1)')).toBeNull()
		expect(signatureImage({ type: 'text' }, 'data:image/png;base64,AAAA')).toBeNull()
	})
})

describe('adding days to a date', () => {
	it('crosses month ends and leap days', () => {
		expect(addDays('2026-01-31', 1)).toBe('2026-02-01')
		expect(addDays('2028-02-28', 1)).toBe('2028-02-29')
		expect(addDays('2026-05-01', 0)).toBe('2026-05-01')
		expect(addDays('nope', 1)).toBe('')
	})
})
