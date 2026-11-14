/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { renderedLabelPosition, requestedLabelPosition, supportsInline } from './labelPosition.ts'

describe('requestedLabelPosition', () => {
	it('defaults to above', () => {
		expect(requestedLabelPosition({ type: 'text' }, undefined)).toBe('above')
		expect(requestedLabelPosition({ type: 'text' }, {})).toBe('above')
	})

	it('uses the form default when the field says nothing', () => {
		expect(requestedLabelPosition({ type: 'text' }, { labelPosition: 'inline' })).toBe('inline')
		expect(requestedLabelPosition({ type: 'text' }, { labelPosition: 'hidden' })).toBe('hidden')
	})

	it('lets the field override the form', () => {
		expect(requestedLabelPosition({ type: 'text', labelPosition: 'above' }, { labelPosition: 'hidden' })).toBe('above')
		expect(requestedLabelPosition({ type: 'text', labelPosition: 'inline' }, { labelPosition: 'hidden' })).toBe('inline')
	})

	it('treats the older hideLabel flag as hidden, ahead of the form default', () => {
		expect(requestedLabelPosition({ type: 'text', hideLabel: true }, { labelPosition: 'inline' })).toBe('hidden')
		expect(requestedLabelPosition({ type: 'text', hideLabel: false }, { labelPosition: 'inline' })).toBe('inline')
	})

	it('prefers an explicit labelPosition over hideLabel', () => {
		expect(requestedLabelPosition({ type: 'text', hideLabel: true, labelPosition: 'inline' }, undefined)).toBe('inline')
	})

	it('ignores values it does not know', () => {
		expect(requestedLabelPosition({ type: 'text', labelPosition: 'left' as never }, { labelPosition: 'right' as never })).toBe('above')
	})
})

describe('renderedLabelPosition', () => {
	it('falls back to above for inline on types that do not support it', () => {
		for (const type of ['textarea', 'radio', 'checkboxes', 'checkbox']) {
			expect(supportsInline(type)).toBe(false)
			expect(renderedLabelPosition({ type }, { labelPosition: 'inline' })).toBe('above')
		}
	})

	it('keeps inline for single-line inputs', () => {
		for (const type of ['text', 'email', 'phone', 'number', 'select', 'date', 'time']) {
			expect(renderedLabelPosition({ type }, { labelPosition: 'inline' })).toBe('inline')
		}
	})

	it('keeps hidden for every type, including the ones that cannot go inline', () => {
		for (const type of ['textarea', 'radio', 'checkboxes', 'checkbox', 'text']) {
			expect(renderedLabelPosition({ type }, { labelPosition: 'hidden' })).toBe('hidden')
		}
	})
})
