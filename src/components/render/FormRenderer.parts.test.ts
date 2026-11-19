// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PublicForm } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FormRenderer from './FormRenderer.vue'

const submitMock = vi.fn()
vi.mock('../../api/public.ts', async (importOriginal) => ({
	...(await importOriginal<typeof import('../../api/public.ts')>()),
	submitPublicForm: (...args: unknown[]) => submitMock(...args),
}))

const ids = ['f_who', 'f_where', 'f_grid', 'f_level', 'f_tint', 'f_stay']

function formWith(required: boolean): PublicForm {
	return {
		token: 'tok',
		versionId: 1,
		fillToken: 'fill.token',
		definition: {
			schemaVersion: 1,
			title: 'Booking',
			settings: {},
			fields: {
				f_who: { type: 'name', label: 'Name', required },
				f_where: { type: 'address', label: 'Address', parts: ['street', 'city'] },
				f_grid: { type: 'matrix', label: 'Rate us', required, questions: [{ value: 'food', label: 'Food' }, { value: 'staff', label: 'Staff' }], options: [{ value: 'good', label: 'Good' }, { value: 'bad', label: 'Bad' }] },
				f_level: { type: 'slider', label: 'Level', min: 0, max: 10, step: 2, suffix: ' pts' },
				f_tint: { type: 'color', label: 'Colour' },
				f_stay: { type: 'daterange', label: 'Stay' },
			},
			pages: [{ id: 'p_1', title: null, rows: ids.map((id, index) => ({ id: 'r_' + index, cells: [{ id: 'c_' + index, span: 12, field: id }] })) }],
			rules: [],
		},
	}
}

beforeEach(() => {
	submitMock.mockReset()
	submitMock.mockResolvedValue({ message: null, redirect: null })
	document.body.innerHTML = ''
})

describe('FormRenderer fields made of parts', () => {
	it('sends each answer in the shape the server takes', async () => {
		const wrapper = mount(FormRenderer, { props: { form: formWith(true) }, attachTo: document.body })
		const inputs = wrapper.findAll('[data-field="f_who"] input')
		expect(inputs.map((input) => input.attributes('autocomplete'))).toEqual(['given-name', 'family-name'])
		await inputs[0].setValue('Ada')
		await inputs[1].setValue('Lovelace')
		const street = wrapper.findAll('[data-field="f_where"] input')
		await street[0].setValue('Main 1')
		await wrapper.find('input[name="af-f_grid-food"][value="good"]').setValue(true)
		await wrapper.find('input[name="af-f_grid-staff"][value="bad"]').setValue(true)
		await wrapper.find('#af-f_level').setValue('4')
		await wrapper.find('#af-f_tint').setValue('#ff8800')
		const dates = wrapper.findAll('[data-field="f_stay"] input')
		await dates[0].setValue('2026-05-01')
		await dates[1].setValue('2026-05-03')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(submitMock).toHaveBeenCalledTimes(1)
		const values = submitMock.mock.calls[0][1].values
		expect(values.f_who).toEqual({ first: 'Ada', last: 'Lovelace' })
		expect(values.f_where).toEqual({ street: 'Main 1', city: '' })
		expect(values.f_grid).toEqual({ food: 'good', staff: 'bad' })
		expect(values.f_level).toBe('4')
		expect(values.f_tint).toBe('#ff8800')
		expect(values.f_stay).toEqual({ start: '2026-05-01', end: '2026-05-03' })
	})

	it('asks for the missing part of a required name and the missing row of a required matrix', async () => {
		const wrapper = mount(FormRenderer, { props: { form: formWith(true) }, attachTo: document.body })
		await wrapper.findAll('[data-field="f_who"] input')[0].setValue('Ada')
		await wrapper.find('input[name="af-f_grid-food"][value="good"]').setValue(true)
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(submitMock).not.toHaveBeenCalled()
		expect(wrapper.find('[data-field="f_who"] .af-error').exists()).toBe(true)
		expect(wrapper.find('[data-field="f_grid"] .af-error').exists()).toBe(true)
	})

	it('shows an unanswered slider as not set and can take the answer back', async () => {
		const wrapper = mount(FormRenderer, { props: { form: formWith(false) }, attachTo: document.body })
		expect(wrapper.find('[data-field="f_level"] output').text()).toBe('Not set')
		await wrapper.find('#af-f_level').setValue('6')
		expect(wrapper.find('[data-field="f_level"] output').text()).toBe('6 pts')
		await wrapper.find('[data-field="f_level"] button').trigger('click')
		expect(wrapper.find('[data-field="f_level"] output').text()).toBe('Not set')
	})
})
