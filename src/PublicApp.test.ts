// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { clearAccess, getAccess } from './access.ts'
import { ApiError } from './api/http.ts'
import PublicApp from './PublicApp.vue'

const fetchPublicForm = vi.hoisted(() => vi.fn())
const unlockPublicForm = vi.hoisted(() => vi.fn())

vi.mock('./api/public.ts', async () => {
	const http = await import('./api/http.ts')
	return { ApiError: http.ApiError, fetchPublicForm, unlockPublicForm }
})

const FORM = {
	token: 'tok',
	versionId: 1,
	signedIn: false,
	uploadLimit: 0,
	fillToken: 'f',
	captcha: null,
	remaining: null,
	definition: { schemaVersion: 1, title: 'Contact', settings: {}, fields: {}, pages: [{ id: 'p_1', title: null, rows: [] }], rules: [] },
}

function needsPassword() {
	return new ApiError(403, 'password_required', [], null, { error: 'password_required' })
}

async function mountPage() {
	const wrapper = mount(PublicApp, { props: { token: 'tok' }, global: { stubs: { FormPage: { template: '<div class="the-form" />' } } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	fetchPublicForm.mockReset()
	unlockPublicForm.mockReset()
	clearAccess('tok')
})

describe('a form whose link has a password', () => {
	it('asks for the password and shows nothing of the form', async () => {
		fetchPublicForm.mockRejectedValue(needsPassword())
		const wrapper = await mountPage()
		expect(wrapper.text()).toContain('This form is protected')
		expect(wrapper.find('input[type="password"]').exists()).toBe(true)
		expect(wrapper.find('.the-form').exists()).toBe(false)
	})

	it('opens the form with the right password and keeps the proof for the next calls', async () => {
		fetchPublicForm.mockRejectedValueOnce(needsPassword()).mockResolvedValueOnce(FORM)
		unlockPublicForm.mockResolvedValue('1700000000.abc')
		const wrapper = await mountPage()
		await wrapper.find('input[type="password"]').setValue('correct horse')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(unlockPublicForm).toHaveBeenCalledWith('tok', 'correct horse')
		expect(getAccess('tok')).toBe('1700000000.abc')
		expect(wrapper.find('.the-form').exists()).toBe(true)
		expect(wrapper.find('input[type="password"]').exists()).toBe(false)
	})

	it('says so for a wrong password and keeps asking', async () => {
		fetchPublicForm.mockRejectedValue(needsPassword())
		unlockPublicForm.mockRejectedValue(new ApiError(422, 'validation', [{ code: 'wrong_password' }] as never))
		const wrapper = await mountPage()
		await wrapper.find('input[type="password"]').setValue('nope nope')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(wrapper.text()).toContain('That password is not right.')
		expect(wrapper.find('.the-form').exists()).toBe(false)
		expect(getAccess('tok')).toBe('')
	})

	it('tells a person who guessed too often to wait', async () => {
		fetchPublicForm.mockRejectedValue(needsPassword())
		unlockPublicForm.mockRejectedValue(new ApiError(429, 'failed'))
		const wrapper = await mountPage()
		await wrapper.find('input[type="password"]').setValue('nope nope')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(wrapper.text()).toContain('Too many attempts.')
	})

	it('does not send an empty password', async () => {
		fetchPublicForm.mockRejectedValue(needsPassword())
		const wrapper = await mountPage()
		await wrapper.find('form').trigger('submit')
		expect(unlockPublicForm).not.toHaveBeenCalled()
	})

	it('forgets a proof that no longer works and asks again', async () => {
		const { setAccess } = await import('./access.ts')
		setAccess('tok', 'old.proof')
		fetchPublicForm.mockRejectedValue(needsPassword())
		const wrapper = await mountPage()
		expect(getAccess('tok')).toBe('')
		expect(wrapper.text()).toContain('This form is protected')
	})
})
