// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PublicForm } from '../../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormPage from './FormPage.vue'

function form(settings: Record<string, unknown>): PublicForm {
	return {
		token: 'tok',
		versionId: 1,
		fillToken: 'x',
		definition: {
			schemaVersion: 1,
			title: 'Inquiry',
			description: 'Tell us more',
			settings,
			fields: {},
			pages: [{ id: 'p_1', title: null, rows: [] }],
			rules: [],
		},
	}
}

describe('FormPage places left', () => {
	it('says how many places are left when the server sends a number', () => {
		const wrapper = mount(FormPage, { props: { form: { ...form({}), remaining: 3 } } })
		expect(wrapper.find('.af-page__remaining').text()).toBe('3 places left')
		expect(mount(FormPage, { props: { form: { ...form({}), remaining: 1 } } }).find('.af-page__remaining').text()).toBe('1 place left')
	})

	it('says nothing otherwise', () => {
		expect(mount(FormPage, { props: { form: form({}) } }).find('.af-page__remaining').exists()).toBe(false)
		expect(mount(FormPage, { props: { form: { ...form({}), remaining: null } } }).find('.af-page__remaining').exists()).toBe(false)
	})
})

describe('FormPage title', () => {
	it('shows the title by default', () => {
		const title = mount(FormPage, { props: { form: form({}) } }).find('h1')
		expect(title.text()).toBe('Inquiry')
		expect(title.classes()).not.toContain('af-page__title--hidden')
	})

	it('hides it visually but keeps the heading when asked', () => {
		const wrapper = mount(FormPage, { props: { form: form({ hideTitle: true }) } })
		expect(wrapper.find('h1').text()).toBe('Inquiry')
		expect(wrapper.find('h1').classes()).toContain('af-page__title--hidden')
		expect(wrapper.text()).toContain('Tell us more')
	})
})
