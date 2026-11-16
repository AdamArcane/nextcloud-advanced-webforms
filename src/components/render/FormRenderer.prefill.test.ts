// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PublicForm } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import FormRenderer from './FormRenderer.vue'

const submitMock = vi.fn()
vi.mock('../../api/public.ts', async (importOriginal) => ({
	...(await importOriginal<typeof import('../../api/public.ts')>()),
	submitPublicForm: (...args: unknown[]) => submitMock(...args),
}))

const form: PublicForm = {
	token: 'tok',
	versionId: 4,
	fillToken: 'fill.token',
	definition: {
		schemaVersion: 1,
		title: 'Sign up',
		settings: {},
		fields: {
			f_name: { type: 'text', label: 'Name' },
			f_plan: { type: 'select', label: 'Plan', options: [{ value: 'basic', label: 'Basic' }, { value: 'pro', label: 'Pro' }] },
			utm_source: { type: 'hidden', label: 'Source', defaultValue: 'direct' },
			f_code: { type: 'text', label: 'Partner code' },
		},
		pages: [{
			id: 'p_1',
			title: null,
			rows: [
				{ id: 'r_1', cells: [{ id: 'c_1', span: 6, field: 'f_name' }, { id: 'c_2', span: 6, field: 'f_plan' }] },
				{ id: 'r_2', cells: [{ id: 'c_3', span: 12, field: 'utm_source' }] },
				{ id: 'r_3', cells: [{ id: 'c_4', span: 12, field: 'f_code' }] },
			],
		}],
		rules: [{ id: 'rule_1', effect: 'show', targets: ['f_code'], when: { all: [{ field: 'utm_source', op: 'eq', value: 'partner' }] } }],
	},
}

function openAt(query: string, preview = false, settings: PublicForm['definition']['settings'] = {}) {
	window.history.replaceState(null, '', '/apps/webforms/f/tok' + query)
	const opened: PublicForm = { ...form, definition: { ...form.definition, settings } }
	return mount(FormRenderer, { props: { form: opened, preview }, attachTo: document.body })
}

beforeEach(() => {
	submitMock.mockReset()
	submitMock.mockResolvedValue({ message: null, redirect: null })
	document.body.innerHTML = ''
})

afterEach(() => {
	window.history.replaceState(null, '', '/')
})

describe('FormRenderer prefill and hidden fields', () => {
	it('fills fields from the address and sends the hidden value without showing it', async () => {
		const wrapper = openAt('?f_name=Ada&f_plan=PRO&utm_source=partner&other=x')
		expect((wrapper.find('#af-f_name').element as HTMLInputElement).value).toBe('Ada')
		expect((wrapper.find('#af-f_plan').element as HTMLSelectElement).value).toBe('pro')
		expect(wrapper.find('[data-field="utm_source"]').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('Source')
		// The hidden value drives a rule like any answer.
		expect(wrapper.find('#af-f_code').exists()).toBe(true)

		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(submitMock.mock.calls[0][1].values).toMatchObject({ f_name: 'Ada', f_plan: 'pro', utm_source: 'partner' })
	})

	it('keeps the defaults when the address gives nothing usable', async () => {
		const wrapper = openAt('?f_plan=enterprise')
		expect((wrapper.find('#af-f_plan').element as HTMLSelectElement).value).toBe('')
		expect(wrapper.find('#af-f_code').exists()).toBe(false)
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(submitMock.mock.calls[0][1].values.utm_source).toBe('direct')
	})

	it('fills nothing when the form switches it off', async () => {
		const wrapper = openAt('?f_name=Ada&utm_source=partner', false, { prefill: false })
		expect((wrapper.find('#af-f_name').element as HTMLInputElement).value).toBe('')
		await wrapper.find('#af-f_name').setValue('Typed')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(submitMock.mock.calls[0][1].values.utm_source).toBe('direct')
	})

	it('ignores the address in the builder preview', () => {
		const wrapper = openAt('?f_name=Ada', true)
		expect((wrapper.find('#af-f_name').element as HTMLInputElement).value).toBe('')
	})
})
