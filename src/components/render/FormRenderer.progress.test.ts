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

const KEY = 'webforms:progress:tok'

const form: PublicForm = {
	token: 'tok',
	versionId: 4,
	fillToken: 'fill.token',
	definition: {
		schemaVersion: 1,
		title: 'Sign up',
		settings: { saveProgress: true },
		fields: {
			f_name: { type: 'text', label: 'Name', defaultValue: 'Nobody' },
			f_plan: { type: 'select', label: 'Plan', options: [{ value: 'basic', label: 'Basic' }, { value: 'pro', label: 'Pro' }] },
			f_about: { type: 'textarea', label: 'About' },
		},
		pages: [
			{ id: 'p_1', title: null, rows: [{ id: 'r_1', cells: [{ id: 'c_1', span: 6, field: 'f_name' }, { id: 'c_2', span: 6, field: 'f_plan' }] }] },
			{ id: 'p_2', title: null, rows: [{ id: 'r_2', cells: [{ id: 'c_3', span: 12, field: 'f_about' }] }] },
		],
		rules: [],
	},
}

function open(options: { preview?: boolean, settings?: PublicForm['definition']['settings'], query?: string } = {}) {
	window.history.replaceState(null, '', '/apps/webforms/f/tok' + (options.query ?? ''))
	const opened: PublicForm = { ...form, definition: { ...form.definition, settings: options.settings ?? form.definition.settings } }
	return mount(FormRenderer, { props: { form: opened, preview: options.preview }, attachTo: document.body })
}

function keep(values: Record<string, unknown>, page = 0, savedAt = Date.now()) {
	window.localStorage.setItem(KEY, JSON.stringify({ format: 1, versionId: 3, savedAt, page, values }))
}

function kept(): { page: number, values: Record<string, unknown> } | null {
	const raw = window.localStorage.getItem(KEY)
	return raw === null ? null : JSON.parse(raw)
}

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
	submitMock.mockReset()
	submitMock.mockResolvedValue({ message: null, redirect: null })
	window.localStorage.clear()
	document.body.innerHTML = ''
})

afterEach(() => {
	vi.useRealTimers()
	window.history.replaceState(null, '', '/')
})

describe('FormRenderer keeping unsent answers', () => {
	it('keeps answers and the page as they change', async () => {
		const wrapper = open()
		expect(wrapper.find('.af-restored').exists()).toBe(false)
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('#af-f_plan').setValue('pro')
		expect(kept()).toBeNull()
		vi.advanceTimersByTime(500)
		expect(kept()).toMatchObject({ page: 0, values: { f_name: 'Ada', f_plan: 'pro' } })

		await wrapper.find('form').trigger('submit')
		await flushPromises()
		vi.advanceTimersByTime(500)
		expect(kept()?.page).toBe(1)
		wrapper.unmount()
	})

	it('tells people they can come back, and when it was saved', async () => {
		const wrapper = open()
		expect(wrapper.find('.af-saveline').text()).toContain('come back to it on this device')
		expect(wrapper.find('.af-saveline').text()).not.toContain('Saved in this browser at')
		await wrapper.find('#af-f_name').setValue('Ada')
		vi.advanceTimersByTime(500)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.af-saveline').text()).toContain('Saved in this browser at')
		wrapper.unmount()
	})

	it('says plainly when the browser will not keep the answers', async () => {
		const real = Object.getOwnPropertyDescriptor(window, 'localStorage') as PropertyDescriptor
		Object.defineProperty(window, 'localStorage', {
			configurable: true,
			value: { getItem: () => null, removeItem: () => undefined, setItem: () => { throw new Error('blocked') } },
		})
		const wrapper = open()
		await wrapper.find('#af-f_name').setValue('Ada')
		vi.advanceTimersByTime(500)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.af-saveline').text()).toContain('could not be saved')
		expect(wrapper.find('.af-saveline--failed').exists()).toBe(true)
		wrapper.unmount()
		Object.defineProperty(window, 'localStorage', real)
	})

	it('shows no such line unless the form asks for it, or in the preview', () => {
		expect(open({ settings: {} }).find('.af-saveline').exists()).toBe(false)
		expect(open({ preview: true }).find('.af-saveline').exists()).toBe(false)
	})

	it('brings them back, before the address and the defaults, on the page they were on', async () => {
		keep({ f_name: 'Ada', f_plan: 'pro', f_about: 'Hello' }, 1)
		const wrapper = open({ query: '?f_name=Grace' })
		expect(wrapper.find('.af-restored').text()).toContain('are back')
		expect((wrapper.find('#af-f_about').element as HTMLTextAreaElement).value).toBe('Hello')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(submitMock.mock.calls[0][1].values).toEqual({ f_name: 'Ada', f_plan: 'pro', f_about: 'Hello' })
		// Sent: nothing is left to come back to, and nothing is written again.
		vi.advanceTimersByTime(500)
		wrapper.unmount()
		expect(kept()).toBeNull()
	})

	it('says so when the form changed and an answer no longer fits', () => {
		keep({ f_name: 'Ada', f_plan: 'enterprise' })
		const wrapper = open()
		expect(wrapper.find('.af-restored').text()).toContain('except some')
		expect((wrapper.find('#af-f_name').element as HTMLInputElement).value).toBe('Ada')
		expect((wrapper.find('#af-f_plan').element as HTMLSelectElement).value).toBe('')
		wrapper.unmount()
	})

	it('starts over on request', async () => {
		keep({ f_name: 'Ada', f_about: 'Hello' }, 1)
		const wrapper = open()
		await wrapper.find('.af-restored button').trigger('click')
		expect(wrapper.find('.af-restored').exists()).toBe(false)
		expect((wrapper.find('#af-f_name').element as HTMLInputElement).value).toBe('Nobody')
		vi.advanceTimersByTime(500)
		expect(kept()?.values).toEqual({ f_name: 'Nobody' })
		wrapper.unmount()
	})

	it('does nothing unless the form asks for it, and never in the preview', async () => {
		keep({ f_name: 'Ada' })
		for (const wrapper of [open({ settings: {} }), open({ preview: true })]) {
			expect((wrapper.find('#af-f_name').element as HTMLInputElement).value).toBe('Nobody')
			await wrapper.find('#af-f_name').setValue('Typed')
			vi.advanceTimersByTime(500)
			wrapper.unmount()
			expect(kept()?.values).toEqual({ f_name: 'Ada' })
		}
	})

	it('forgets answers older than 30 days', () => {
		keep({ f_name: 'Ada' }, 0, Date.now() - 31 * 24 * 3600 * 1000)
		const wrapper = open()
		expect(wrapper.find('.af-restored').exists()).toBe(false)
		expect((wrapper.find('#af-f_name').element as HTMLInputElement).value).toBe('Nobody')
		wrapper.unmount()
	})
})
