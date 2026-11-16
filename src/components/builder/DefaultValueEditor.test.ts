// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormField } from '../../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DefaultValueEditor from './DefaultValueEditor.vue'

const options = [{ value: 'aaa', label: 'A' }, { value: 'bbb', label: 'B' }, { value: 'ccc', label: 'C' }]

function editor(field: FormField) {
	return mount(DefaultValueEditor, { props: { fieldId: 'f_x', field } })
}

function last(wrapper: ReturnType<typeof editor>): unknown {
	return wrapper.emitted('change')?.at(-1)?.[0]
}

describe('DefaultValueEditor', () => {
	it('keeps text as text, and clears it when emptied', async () => {
		const wrapper = editor({ type: 'text', label: 'L' })
		await wrapper.find('input').setValue('Ada')
		expect(last(wrapper)).toBe('Ada')
		await wrapper.find('input').setValue('  ')
		expect(last(wrapper)).toBeUndefined()
	})

	it('stores a number as a number', async () => {
		const wrapper = editor({ type: 'number', label: 'L' })
		await wrapper.find('input').setValue('12.5')
		expect(last(wrapper)).toBe(12.5)
	})

	it('picks one option, or none', async () => {
		const wrapper = editor({ type: 'radio', label: 'L', options })
		await wrapper.find('select').setValue('bbb')
		expect(last(wrapper)).toBe('bbb')
		await wrapper.find('select').setValue('')
		expect(last(wrapper)).toBeUndefined()
	})

	it('ticks several options in the form order', async () => {
		const wrapper = editor({ type: 'checkboxes', label: 'L', options, defaultValue: ['ccc'] })
		await wrapper.findAll('input[type=checkbox]')[0].setValue(true)
		expect(last(wrapper)).toEqual(['aaa', 'ccc'])
	})

	it('uses a date input for a date', async () => {
		const wrapper = editor({ type: 'date', label: 'L' })
		const input = wrapper.find('input[type=date]')
		await input.setValue('2026-12-01')
		await input.trigger('change')
		expect(last(wrapper)).toBe('2026-12-01')
	})
})
