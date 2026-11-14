// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormField } from '../../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FieldInput from './FieldInput.vue'

function render(field: FormField, modelValue: unknown = '') {
	return mount(FieldInput, { props: { fieldId: 'f_test', field, modelValue, required: false, error: null } })
}

describe('type specific properties', () => {
	it.each([1, 2, 3, 4, 5, 6])('renders a heading at level %i', (level) => {
		expect(render({ type: 'heading', text: 'Hi', level }).find(`h${level}`).exists()).toBe(true)
	})

	it('falls back to h2 for a level it does not know', () => {
		expect(render({ type: 'heading', text: 'Hi', level: 9 }).find('h2').exists()).toBe(true)
	})

	it('passes the textarea rows and minimum length', () => {
		const area = render({ type: 'textarea', label: 'A', rows: 9, minLength: 4 }).find('textarea')
		expect(area.attributes('rows')).toBe('9')
		expect(area.attributes('minlength')).toBe('4')
	})

	it('shows a prefix and a suffix around a number', () => {
		const wrapper = render({ type: 'number', label: 'A', prefix: '$', suffix: 'kg' })
		const affixes = wrapper.findAll('.af-affix').map((node) => node.text())
		expect(affixes).toEqual(['$', 'kg'])
	})

	it('limits a date and a time with min and max', () => {
		const date = render({ type: 'date', label: 'A', min: '2026-01-01', max: '2026-12-31' }).find('input')
		expect(date.attributes('min')).toBe('2026-01-01')
		expect(date.attributes('max')).toBe('2026-12-31')
		const time = render({ type: 'time', label: 'A', min: '09:00' }).find('input')
		expect(time.attributes('min')).toBe('09:00')
	})

	it('lays options out in columns', () => {
		const wrapper = render({ type: 'radio', label: 'A', columns: 3, options: [{ value: 'aa', label: 'A' }] })
		expect(wrapper.find('.af-options').attributes('style')).toContain('repeat(3')
	})

	it('styles a divider', () => {
		expect(render({ type: 'divider', style: 'dashed' }).find('hr').classes()).toContain('af-divider--dashed')
	})
})
