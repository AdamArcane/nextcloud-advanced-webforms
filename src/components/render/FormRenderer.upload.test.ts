// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormField, PublicForm } from '../../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormRenderer from './FormRenderer.vue'

function render(field: FormField, extra: Partial<PublicForm> = {}, props: Record<string, unknown> = { preview: true }) {
	const form: PublicForm = {
		token: 't',
		versionId: 1,
		fillToken: 'x',
		signedIn: false,
		definition: {
			schemaVersion: 1,
			title: 'T',
			settings: {},
			fields: { f_cv: field },
			pages: [{ id: 'p_1', title: null, rows: [{ id: 'r_1aa', cells: [{ id: 'c_1aa', span: 12, field: 'f_cv' }] }] }],
			rules: [],
		},
		...extra,
	}
	return mount(FormRenderer, { props: { form, ...props } })
}

describe('upload field on the public form', () => {
	it('offers a file picker limited to the accepted types, with the limits in plain words', () => {
		const wrapper = render({ type: 'file', label: 'CV', maxFiles: 3, maxSizeMb: 5, accept: ['documents'] })
		const input = wrapper.find('input[type="file"]')
		expect(input.attributes('accept')).toContain('.pdf')
		expect(input.attributes('accept')).not.toContain('.png')
		expect(input.attributes('multiple')).toBeDefined()
		expect(wrapper.text()).toContain('Up to 3 files, 5 MB each')
	})

	it('takes one file by default', () => {
		const wrapper = render({ type: 'file', label: 'CV' })
		expect(wrapper.find('input[type="file"]').attributes('multiple')).toBeUndefined()
		expect(wrapper.text()).toContain('One file, up to 10 MB')
	})

	it('asks anonymous visitors to sign in when the field is for signed-in users only', () => {
		const wrapper = render({ type: 'file', label: 'CV', audience: 'login' }, {}, { preview: false })
		expect(wrapper.find('input[type="file"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('sign in to upload')
		expect(wrapper.find('a[href*="login"]').exists()).toBe(true)
	})

	it('lets signed-in users upload to such a field', () => {
		const wrapper = render({ type: 'file', label: 'CV', audience: 'login' }, { signedIn: true }, { preview: false })
		expect(wrapper.find('input[type="file"]').exists()).toBe(true)
	})

	it('marks a required upload field as missing in the preview check', async () => {
		const wrapper = render({ type: 'file', label: 'CV', required: true }, {}, { preview: true, validate: true })
		await wrapper.find('form').trigger('submit')
		expect(wrapper.text()).toContain('This field is required.')
	})
})
