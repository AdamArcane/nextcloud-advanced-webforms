// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormSettings, OwnerForm } from '../../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { emptyDefinition } from '../../builder/definition.ts'
import { FORM_STATUS_PUBLISHED } from '../../types.ts'
import FormProperties from './FormProperties.vue'

function mountProps(settings: FormSettings = {}, form: OwnerForm | null = null) {
	const definition = { ...emptyDefinition('T'), settings }
	return mount(FormProperties, { props: { section: 'sharing' as const, definition, shareUrl: null, form } })
}

function patches(wrapper: ReturnType<typeof mountProps>): Partial<FormSettings>[] {
	return (wrapper.emitted('settings') ?? []).map((call) => call[0] as Partial<FormSettings>)
}

describe('response limit in the form settings', () => {
	it('stores a whole number as the limit', async () => {
		const wrapper = mountProps()
		const input = wrapper.find('input[type="number"]')
		await input.setValue('25')
		expect(patches(wrapper).at(-1)).toEqual({ maxResponses: 25 })
	})

	it('clears the limit, and the count for respondents with it', async () => {
		const wrapper = mountProps({ maxResponses: 25, showRemaining: true })
		const input = wrapper.find('input[type="number"]')
		expect((input.element as HTMLInputElement).value).toBe('25')
		await input.setValue('')
		expect(patches(wrapper).at(-1)).toEqual({ maxResponses: undefined, showRemaining: undefined })
		await input.setValue('0')
		expect(patches(wrapper).at(-1)).toEqual({ maxResponses: undefined, showRemaining: undefined })
		await input.setValue('2.5')
		expect(patches(wrapper).at(-1)).toEqual({ maxResponses: undefined, showRemaining: undefined })
	})

	it('only offers the count for respondents when there is a limit', () => {
		const label = 'Show respondents how many places are left'
		const find = (wrapper: ReturnType<typeof mountProps>) => wrapper.findAll('input[type="checkbox"]')
			.find((node) => node.element.closest('.checkbox-radio-switch')?.textContent?.includes(label))!
		expect((find(mountProps()).element as HTMLInputElement).disabled).toBe(true)
		expect((find(mountProps({ maxResponses: 5 })).element as HTMLInputElement).disabled).toBe(false)
	})
})

describe('waiting list in the form settings', () => {
	const label = 'Keep a waiting list when the form is full'
	const find = (wrapper: ReturnType<typeof mountProps>) => wrapper.findAll('input[type="checkbox"]')
		.find((node) => node.element.closest('.checkbox-radio-switch')?.textContent?.includes(label))!

	it('is only offered with a limit', () => {
		expect((find(mountProps()).element as HTMLInputElement).disabled).toBe(true)
		expect((find(mountProps({ maxResponses: 5 })).element as HTMLInputElement).disabled).toBe(false)
	})

	it('turns on and off, taking its message with it', async () => {
		const wrapper = mountProps({ maxResponses: 5 })
		await find(wrapper).setValue(true)
		expect(patches(wrapper).at(-1)).toEqual({ whenFull: 'waitlist' })
		const on = mountProps({ maxResponses: 5, whenFull: 'waitlist', waitlistMessage: 'Soon.' })
		await find(on).setValue(false)
		expect(patches(on).at(-1)).toEqual({ whenFull: undefined, waitlistMessage: undefined })
	})

	it('drops the waiting list with the limit', async () => {
		const wrapper = mountProps({ maxResponses: 5, whenFull: 'waitlist' })
		await wrapper.find('input[type="number"]').setValue('')
		expect(patches(wrapper).at(-1)).toEqual({ maxResponses: undefined, showRemaining: undefined, whenFull: undefined, waitlistMessage: undefined })
	})
})

describe('opening and closing times in the form settings', () => {
	it('stores the times as unix seconds', async () => {
		const wrapper = mountProps()
		const [opens, closes] = wrapper.findAll('input[type="datetime-local"]')
		await opens.setValue('2027-03-01T09:00')
		await opens.trigger('change')
		expect(patches(wrapper).at(-1)).toEqual({ opensAt: Math.floor(new Date(2027, 2, 1, 9, 0).getTime() / 1000) })
		await closes.setValue('')
		await closes.trigger('change')
		expect(patches(wrapper).at(-1)).toEqual({ closesAt: undefined })
	})

	it('warns when the form would close before it opens', () => {
		expect(mountProps({ opensAt: 1800000000, closesAt: 1800003600 }).find('[role="alert"]').exists()).toBe(false)
		const wrapper = mountProps({ opensAt: 1800003600, closesAt: 1800000000 })
		expect(wrapper.find('[role="alert"]').text()).toBe('The closing time must be after the opening time.')
	})

	it('shows what the published form is doing now', () => {
		const form: OwnerForm = {
			id: 1,
			title: 'T',
			description: null,
			status: FORM_STATUS_PUBLISHED,
			publicToken: 'tok',
			latestVersionId: 2,
			createdAt: 0,
			updatedAt: 0,
			availability: { state: 'full', opensAt: null, closesAt: null, cap: 10, responses: 10 },
		}
		expect(mountProps({ maxResponses: 10 }, form).text()).toContain('Full: it takes no more responses. 10 of 10 responses.')
		expect(mountProps({ maxResponses: 10 }).text()).not.toContain('Full:')
	})
})
