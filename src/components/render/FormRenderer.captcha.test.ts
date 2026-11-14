// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PublicForm } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { sha256Hex } from '../../captcha/sha256.ts'
import FormRenderer from './FormRenderer.vue'

const submitMock = vi.fn()
const salt = 'ab12cd34ef56ab12cd34ef56?expires=1900000000'
const challengeMock = vi.fn()
vi.mock('../../api/public.ts', async (importOriginal) => ({
	...(await importOriginal<typeof import('../../api/public.ts')>()),
	submitPublicForm: (...args: unknown[]) => submitMock(...args),
	fetchCaptchaChallenge: () => challengeMock(),
}))

function makeForm(captcha: PublicForm['captcha']): PublicForm {
	return {
		token: 'tok',
		versionId: 4,
		fillToken: 'fill.token',
		captcha,
		definition: {
			schemaVersion: 1,
			title: 'Inquiry',
			fields: { f_name: { type: 'text', label: 'Name' } },
			pages: [{ id: 'p_1', title: null, rows: [{ id: 'r_1', cells: [{ id: 'c_1', span: 12, field: 'f_name' }] }] }],
			rules: [],
		},
	}
}

beforeEach(() => {
	submitMock.mockReset()
	challengeMock.mockReset()
	challengeMock.mockResolvedValue({ algorithm: 'SHA-256', challenge: sha256Hex(salt + 77), salt, signature: 'sig', maxnumber: 200 })
	document.body.innerHTML = ''
})

describe('FormRenderer spam check', () => {
	it('solves the built-in check and sends the answer with the form', async () => {
		submitMock.mockResolvedValue({ message: null, redirect: null })
		const wrapper = mount(FormRenderer, { props: { form: makeForm({ provider: 'builtin', siteKey: '' }) }, attachTo: document.body })
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(submitMock).toHaveBeenCalledTimes(1)
		const payload = JSON.parse(atob(submitMock.mock.calls[0][1].captcha))
		expect(payload.number).toBe(77)
		expect(payload.signature).toBe('sig')
	})

	it('does not send the form when the check could not be solved', async () => {
		challengeMock.mockRejectedValue(new Error('offline'))
		const wrapper = mount(FormRenderer, { props: { form: makeForm({ provider: 'builtin', siteKey: '' }) }, attachTo: document.body })
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(submitMock).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('spam check')
	})

	it('asks for a new challenge after a failed attempt', async () => {
		const { ApiError } = await import('../../api/public.ts')
		submitMock.mockRejectedValue(new ApiError(422, 'validation', [{ code: 'captcha_failed' }]))
		const wrapper = mount(FormRenderer, { props: { form: makeForm({ provider: 'builtin', siteKey: '' }) }, attachTo: document.body })
		await flushPromises()
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(challengeMock).toHaveBeenCalledTimes(2)
		expect(wrapper.text()).toContain('The spam check was not passed')
	})

	it('shows no check when the server asks for none', async () => {
		submitMock.mockResolvedValue({ message: null, redirect: null })
		const wrapper = mount(FormRenderer, { props: { form: makeForm(null) }, attachTo: document.body })
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(challengeMock).not.toHaveBeenCalled()
		expect(submitMock.mock.calls[0][1].captcha).toBeUndefined()
	})

	it('leaves the check out of the builder preview', async () => {
		const wrapper = mount(FormRenderer, { props: { form: makeForm({ provider: 'builtin', siteKey: '' }), preview: true }, attachTo: document.body })
		await flushPromises()
		expect(challengeMock).not.toHaveBeenCalled()
		expect(wrapper.find('.af-captcha').exists()).toBe(false)
	})
})
