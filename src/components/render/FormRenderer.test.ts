// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PublicForm } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/public.ts'
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
		title: 'Inquiry',
		settings: { submitLabel: 'Send it' },
		fields: {
			f_name: { type: 'text', label: 'Name', required: true },
			f_kind: { type: 'select', label: 'Kind', required: true, options: [{ value: 'new', label: 'New' }, { value: 'support', label: 'Support' }] },
			f_url: { type: 'text', label: 'Website' },
			f_tags: { type: 'checkboxes', label: 'Tags', options: [{ value: 'aaa', label: 'A' }, { value: 'bbb', label: 'B' }] },
			f_hidden: { type: 'text', label: 'Hidden label', hideLabel: true },
			f_note: { type: 'paragraph', text: 'Hello <img src=x onerror=alert(1)> **bold**' },
		},
		pages: [{
			id: 'p_1',
			title: null,
			rows: [
				{ id: 'r_1', cells: [{ id: 'c_1', span: 6, field: 'f_name' }, { id: 'c_2', span: 6, field: 'f_kind' }] },
				{ id: 'r_2', cells: [{ id: 'c_3', span: 12, field: 'f_url' }] },
				{ id: 'r_3', cells: [{ id: 'c_4', span: 12, field: 'f_tags' }] },
				{ id: 'r_4', cells: [{ id: 'c_5', span: 12, field: 'f_hidden' }] },
				{ id: 'r_5', cells: [{ id: 'c_6', span: 12, field: 'f_note' }] },
			],
		}],
		rules: [{
			id: 'rule_1',
			effect: 'show',
			targets: ['f_url'],
			when: { all: [{ field: 'f_kind', op: 'eq', value: 'support' }] },
		}],
	},
}

function render() {
	return mount(FormRenderer, { props: { form }, attachTo: document.body })
}

beforeEach(() => {
	submitMock.mockReset()
	document.body.innerHTML = ''
})

describe('FormRenderer', () => {
	it('shows only the fields the rules allow', async () => {
		const wrapper = render()
		expect(wrapper.find('#af-f_url').exists()).toBe(false)

		await wrapper.find('#af-f_kind').setValue('support')
		expect(wrapper.find('#af-f_url').exists()).toBe(true)

		await wrapper.find('#af-f_kind').setValue('new')
		expect(wrapper.find('#af-f_url').exists()).toBe(false)
	})

	it('uses the configured submit label', () => {
		expect(render().find('button[type=submit]').text()).toBe('Send it')
	})

	it('blocks submit and reports missing required fields', async () => {
		const wrapper = render()
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(submitMock).not.toHaveBeenCalled()
		const alerts = wrapper.findAll('.af-error').map((node) => node.text())
		expect(alerts).toHaveLength(2)
		expect(wrapper.find('#af-f_name').attributes('aria-invalid')).toBe('true')
		expect(wrapper.find('#af-f_name').attributes('aria-required')).toBe('true')
	})

	it('submits visible values only and never hidden ones', async () => {
		submitMock.mockResolvedValue({ message: null, redirect: null })
		const wrapper = render()

		await wrapper.find('#af-f_kind').setValue('support')
		await wrapper.find('#af-f_url').setValue('https://example.com')
		await wrapper.find('#af-f_kind').setValue('new') // hides f_url again
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('input[value=bbb]').setValue(true)
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(submitMock).toHaveBeenCalledTimes(1)
		const [token, body] = submitMock.mock.calls[0]
		expect(token).toBe('tok')
		expect(body.versionId).toBe(4)
		expect(body.fillToken).toBe('fill.token')
		expect(body.hp).toBe('')
		expect(body.values.f_name).toBe('Ada')
		expect(body.values.f_kind).toBe('new')
		expect(body.values.f_tags).toEqual(['bbb'])
		expect('f_url' in body.values).toBe(false)
		expect('f_note' in body.values).toBe(false)
	})

	it('sends the honeypot value when something fills it', async () => {
		submitMock.mockResolvedValue({ message: null, redirect: null })
		const wrapper = render()
		await wrapper.find('#af-f_name').setValue('Bot')
		await wrapper.find('#af-f_kind').setValue('new')
		await wrapper.find('input[name=website]').setValue('spam')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(submitMock.mock.calls[0][1].hp).toBe('spam')
	})

	it('shows the thank-you message after success', async () => {
		submitMock.mockResolvedValue({ message: 'Got it, thanks.', redirect: null })
		const wrapper = render()
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('#af-f_kind').setValue('new')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(wrapper.text()).toContain('Got it, thanks.')
		expect(wrapper.find('form').exists()).toBe(false)
	})

	it('shows server field errors next to the field', async () => {
		submitMock.mockRejectedValue(new ApiError(422, 'validation', [{ field: 'f_name', code: 'too_long' }]))
		const wrapper = render()
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('#af-f_kind').setValue('new')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[data-field=f_name] .af-error').text()).toContain('too long')
		expect(wrapper.find('form').exists()).toBe(true)
	})

	it('asks for a fresh token when the server says it expired', async () => {
		submitMock.mockRejectedValue(new ApiError(422, 'validation', [{ code: 'token_expired' }]))
		const wrapper = render()
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('#af-f_kind').setValue('new')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(wrapper.emitted('refreshToken')).toHaveLength(1)
	})

	it('shows a rate limit message', async () => {
		submitMock.mockRejectedValue(new ApiError(429, 'failed'))
		const wrapper = render()
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('#af-f_kind').setValue('new')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(wrapper.text()).toContain('Too many attempts')
	})

	it('keeps hidden labels in the DOM for screen readers', () => {
		const label = render().find('label[for=af-f_hidden]')
		expect(label.exists()).toBe(true)
		expect(label.classes()).toContain('af-sr-only')
	})

	it('renders paragraph markdown without creating raw HTML elements', () => {
		const wrapper = render()
		expect(wrapper.find('.af-markdown strong').text()).toBe('bold')
		expect(wrapper.find('.af-markdown img').exists()).toBe(false)
		expect(wrapper.find('.af-markdown').text()).toContain('<img src=x onerror=alert(1)>')
	})

	it('does not submit in preview mode', async () => {
		const wrapper = mount(FormRenderer, { props: { form, preview: true } })
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('form').trigger('submit')
		expect(submitMock).not.toHaveBeenCalled()
	})
})
