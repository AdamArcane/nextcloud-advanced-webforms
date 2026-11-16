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

function definition(settings: PublicForm['definition']['settings'], twoPages = true): PublicForm {
	return {
		token: 'tok',
		versionId: 4,
		fillToken: 'fill.token',
		definition: {
			schemaVersion: 1,
			title: 'Sign up',
			settings,
			fields: {
				f_name: { type: 'text', label: 'Name', required: true },
				f_plan: { type: 'select', label: 'Plan', options: [{ value: 'basic', label: 'Basic plan' }, { value: 'pro', label: 'Pro plan' }] },
				f_about: { type: 'textarea', label: 'About' },
			},
			pages: twoPages
				? [
					{ id: 'p_1', title: 'You', rows: [{ id: 'r_1', cells: [{ id: 'c_1', span: 6, field: 'f_name' }, { id: 'c_2', span: 6, field: 'f_plan' }] }] },
					{ id: 'p_2', title: null, rows: [{ id: 'r_2', cells: [{ id: 'c_3', span: 12, field: 'f_about' }] }] },
				]
				: [{ id: 'p_1', title: null, rows: [{ id: 'r_1', cells: [{ id: 'c_1', span: 6, field: 'f_name' }, { id: 'c_2', span: 6, field: 'f_plan' }, { id: 'c_3', span: 12, field: 'f_about' }] }] }],
			rules: [],
		},
	}
}

function open(settings: PublicForm['definition']['settings'], twoPages = true, preview = false) {
	document.body.innerHTML = ''
	return mount(FormRenderer, { props: { form: definition(settings, twoPages), preview }, attachTo: document.body })
}

async function next(wrapper: ReturnType<typeof open>) {
	await wrapper.find('form').trigger('submit')
	await flushPromises()
}

beforeEach(() => {
	submitMock.mockReset()
	submitMock.mockResolvedValue({ message: null, redirect: null })
	window.localStorage.clear()
})

describe('FormRenderer review step', () => {
	it('adds nothing unless the form asks for it', async () => {
		const wrapper = open({})
		await wrapper.find('#af-f_name').setValue('Ada')
		await next(wrapper)
		expect(wrapper.find('.af-review').exists()).toBe(false)
		expect(wrapper.text()).toContain('Send')
	})

	it('lists the answers last, with the owner’s heading, and sends from there', async () => {
		const wrapper = open({ reviewStep: true, reviewTitle: 'Check it over' })
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('#af-f_plan').setValue('pro')
		await next(wrapper)
		await wrapper.find('#af-f_about').setValue('Hello there')
		expect(wrapper.find('.af-review').exists()).toBe(false)
		await next(wrapper)

		expect(wrapper.find('.af-review__title').text()).toBe('Check it over')
		const text = wrapper.find('.af-review__box').text()
		expect(text).toContain('Ada')
		expect(text).toContain('Pro plan')
		expect(text).toContain('Hello there')
		expect(wrapper.find('.af-review__box').attributes('tabindex')).toBe('0')
		expect(wrapper.text()).toContain('Step 3 of 3')
		// Sending moves to the review.
		expect(wrapper.find('button[type="submit"]').text()).toBe('Send')

		await next(wrapper)
		expect(submitMock.mock.calls[0][1].values).toMatchObject({ f_name: 'Ada', f_plan: 'pro', f_about: 'Hello there' })
	})

	it('falls back to the default heading', async () => {
		const wrapper = open({ reviewStep: true, reviewTitle: '  ' })
		await wrapper.find('#af-f_name').setValue('Ada')
		await next(wrapper)
		await next(wrapper)
		expect(wrapper.find('.af-review__title').text()).toBe('Review your answers')
	})

	it('goes back to the review after changing an answer from it', async () => {
		const wrapper = open({ reviewStep: true })
		await wrapper.find('#af-f_name').setValue('Ada')
		await next(wrapper)
		await next(wrapper)
		const edit = wrapper.findAll('.af-review__head button')[0]
		await edit.trigger('click')
		expect((wrapper.find('#af-f_name').element as HTMLInputElement).value).toBe('Ada')
		await wrapper.find('#af-f_name').setValue('Grace')
		await next(wrapper)
		expect(wrapper.find('.af-review__box').text()).toContain('Grace')
		expect(submitMock).not.toHaveBeenCalled()
	})

	it('flags a required answer that is missing and leads to it', async () => {
		// Kept answers can put someone on the review with a required answer still empty.
		window.localStorage.setItem('webforms:progress:tok', JSON.stringify({ format: 1, savedAt: Date.now(), page: 2, values: { f_plan: 'pro' } }))
		const wrapper = open({ reviewStep: true, saveProgress: true })
		expect(wrapper.find('.af-review__item--missing').text()).toContain('Required')
		await wrapper.find('.af-review__fix').trigger('click')
		expect(wrapper.find('#af-f_name').exists()).toBe(true)
		// Sending from the review is stopped and points at the field too.
		await wrapper.find('#af-f_name').setValue('Ada')
		await next(wrapper)
		expect(wrapper.find('.af-review__item--missing').exists()).toBe(false)
	})

	it('works on a form with one page, without page headings', async () => {
		const wrapper = open({ reviewStep: true }, false)
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('#af-f_about').setValue('Hello')
		await next(wrapper)
		expect(wrapper.find('.af-review__title').exists()).toBe(true)
		expect(wrapper.find('.af-review__head').exists()).toBe(false)
		expect(wrapper.find('.af-review__box').text()).toContain('Hello')
		await next(wrapper)
		expect(submitMock).toHaveBeenCalledTimes(1)
	})

	it('counts optional questions that were skipped', async () => {
		const wrapper = open({ reviewStep: true })
		await wrapper.find('#af-f_name').setValue('Ada')
		await next(wrapper)
		await next(wrapper)
		expect(wrapper.find('.af-review__skipped').text()).toBe('2 optional questions skipped.')
	})

	it('shows in the preview and opens on the review when a kept answer was there', async () => {
		expect(open({ reviewStep: true }, true, true).find('form').exists()).toBe(true)
		window.localStorage.setItem('webforms:progress:tok', JSON.stringify({ format: 1, savedAt: Date.now(), page: 2, values: { f_name: 'Ada' } }))
		const wrapper = open({ reviewStep: true, saveProgress: true })
		expect(wrapper.find('.af-review__box').text()).toContain('Ada')
	})
})
