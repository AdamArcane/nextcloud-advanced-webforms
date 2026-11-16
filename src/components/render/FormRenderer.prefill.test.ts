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
			f_name: { type: 'text', label: 'Name', prefill: 'name' },
			f_plan: { type: 'select', label: 'Plan', prefill: 'plan', options: [{ value: 'basic', label: 'Basic' }, { value: 'pro', label: 'Pro' }] },
			f_source: { type: 'hidden', label: 'Source', prefill: 'source', defaultValue: 'direct' },
			f_code: { type: 'text', label: 'Partner code' },
		},
		pages: [{
			id: 'p_1',
			title: null,
			rows: [
				{ id: 'r_1', cells: [{ id: 'c_1', span: 6, field: 'f_name' }, { id: 'c_2', span: 6, field: 'f_plan' }] },
				{ id: 'r_2', cells: [{ id: 'c_3', span: 12, field: 'f_source' }] },
				{ id: 'r_3', cells: [{ id: 'c_4', span: 12, field: 'f_code' }] },
			],
		}],
		rules: [{ id: 'rule_1', effect: 'show', targets: ['f_code'], when: { all: [{ field: 'f_source', op: 'eq', value: 'partner' }] } }],
	},
}

function openAt(query: string, preview = false) {
	window.history.replaceState(null, '', '/apps/webforms/f/tok' + query)
	return mount(FormRenderer, { props: { form, preview }, attachTo: document.body })
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
		const wrapper = openAt('?name=Ada&plan=PRO&source=partner&other=x')
		expect((wrapper.find('#af-f_name').element as HTMLInputElement).value).toBe('Ada')
		expect((wrapper.find('#af-f_plan').element as HTMLSelectElement).value).toBe('pro')
		expect(wrapper.find('[data-field="f_source"]').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('Source')
		// The hidden value drives a rule like any answer.
		expect(wrapper.find('#af-f_code').exists()).toBe(true)

		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(submitMock.mock.calls[0][1].values).toMatchObject({ f_name: 'Ada', f_plan: 'pro', f_source: 'partner' })
	})

	it('keeps the defaults when the address gives nothing usable', async () => {
		const wrapper = openAt('?plan=enterprise')
		expect((wrapper.find('#af-f_plan').element as HTMLSelectElement).value).toBe('')
		expect(wrapper.find('#af-f_code').exists()).toBe(false)
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(submitMock.mock.calls[0][1].values.f_source).toBe('direct')
	})

	it('ignores the address in the builder preview', () => {
		const wrapper = openAt('?name=Ada', true)
		expect((wrapper.find('#af-f_name').element as HTMLInputElement).value).toBe('')
	})
})
