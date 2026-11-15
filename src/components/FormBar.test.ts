// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormBar from './FormBar.vue'

function bar(renamable: boolean) {
	return mount(FormBar, { props: { formId: 1, active: 'edit' as const, title: 'Contact', renamable }, attachTo: document.body })
}

describe('renaming a form in the header', () => {
	it('turns the name into a field on double-click and keeps what is typed on Enter', async () => {
		const wrapper = bar(true)
		await wrapper.find('h2').trigger('dblclick')
		const input = wrapper.find('input.af-bar__rename')
		expect((input.element as HTMLInputElement).value).toBe('Contact')
		await input.setValue('  Contact us  ')
		await input.trigger('keydown', { key: 'Enter' })
		expect(wrapper.emitted('rename')).toEqual([['Contact us']])
		expect(wrapper.find('input.af-bar__rename').exists()).toBe(false)
	})

	it('keeps the name when the field loses focus', async () => {
		const wrapper = bar(true)
		await wrapper.find('h2').trigger('dblclick')
		await wrapper.find('input.af-bar__rename').setValue('New name')
		await wrapper.find('input.af-bar__rename').trigger('blur')
		expect(wrapper.emitted('rename')).toEqual([['New name']])
	})

	it('changes nothing on Escape, for an empty name, or for the same name', async () => {
		const wrapper = bar(true)
		await wrapper.find('h2').trigger('dblclick')
		await wrapper.find('input.af-bar__rename').setValue('Other')
		await wrapper.find('input.af-bar__rename').trigger('keydown', { key: 'Escape' })
		expect(wrapper.find('h2').text()).toBe('Contact')

		await wrapper.find('h2').trigger('dblclick')
		await wrapper.find('input.af-bar__rename').setValue('   ')
		await wrapper.find('input.af-bar__rename').trigger('keydown', { key: 'Enter' })

		await wrapper.find('h2').trigger('dblclick')
		await wrapper.find('input.af-bar__rename').trigger('keydown', { key: 'Enter' })
		expect(wrapper.emitted('rename')).toBeUndefined()
	})

	it('can be started from the keyboard', async () => {
		const wrapper = bar(true)
		expect(wrapper.find('h2').attributes('tabindex')).toBe('0')
		await wrapper.find('h2').trigger('keydown', { key: 'F2' })
		expect(wrapper.find('input.af-bar__rename').exists()).toBe(true)
	})

	it('does nothing where the name cannot be changed', async () => {
		const wrapper = bar(false)
		await wrapper.find('h2').trigger('dblclick')
		expect(wrapper.find('input.af-bar__rename').exists()).toBe(false)
		expect(wrapper.find('h2').attributes('tabindex')).toBeUndefined()
	})
})
