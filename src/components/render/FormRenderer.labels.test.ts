// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormField, FormSettings, PublicForm } from '../../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormRenderer from './FormRenderer.vue'

function render(settings: FormSettings, fields: Record<string, FormField>) {
	const ids = Object.keys(fields)
	const form: PublicForm = {
		token: 't',
		versionId: 1,
		fillToken: 'x',
		definition: {
			schemaVersion: 1,
			title: 'T',
			settings,
			fields,
			pages: [{ id: 'p_1', title: null, rows: ids.map((id, i) => ({ id: 'r_' + i + 'aa', cells: [{ id: 'c_' + i + 'aa', span: 12, field: id }] })) }],
			rules: [],
		},
	}
	return mount(FormRenderer, { props: { form, preview: true } })
}

function position(wrapper: ReturnType<typeof render>, id: string): string | undefined {
	return wrapper.find(`[data-field="${id}"]`).attributes('data-label-position')
}

describe('label position in the public renderer', () => {
	const fields: Record<string, FormField> = {
		f_name: { type: 'text', label: 'Name' },
		f_story: { type: 'textarea', label: 'Story' },
		f_kind: { type: 'radio', label: 'Kind', options: [{ value: 'aaa', label: 'A' }] },
		f_agree: { type: 'checkbox', label: 'I agree' },
	}

	it('draws labels above by default', () => {
		const wrapper = render({}, fields)
		expect(position(wrapper, 'f_name')).toBe('above')
		expect(wrapper.find('[data-field="f_name"]').classes()).not.toContain('af-field--inline')
		expect(wrapper.find('[data-field="f_name"] label').classes()).not.toContain('af-sr-only')
	})

	it('applies the form default to every field that supports it', () => {
		const wrapper = render({ labelPosition: 'inline' }, fields)
		expect(position(wrapper, 'f_name')).toBe('inline')
		expect(wrapper.find('[data-field="f_name"]').classes()).toContain('af-field--inline')
	})

	it('falls back to above for long text, choices and checkboxes', () => {
		const wrapper = render({ labelPosition: 'inline' }, fields)
		for (const id of ['f_story', 'f_kind', 'f_agree']) {
			expect(position(wrapper, id)).toBe('above')
			expect(wrapper.find(`[data-field="${id}"]`).classes()).not.toContain('af-field--inline')
		}
	})

	it('lets a field override the form default', () => {
		const wrapper = render({ labelPosition: 'inline' }, { f_name: { type: 'text', label: 'Name', labelPosition: 'above' } })
		expect(position(wrapper, 'f_name')).toBe('above')
	})

	it('hides labels visually but keeps them for screen readers, for every kind of field', () => {
		const wrapper = render({ labelPosition: 'hidden' }, fields)
		expect(wrapper.find('[data-field="f_name"] label').classes()).toContain('af-sr-only')
		expect(wrapper.find('[data-field="f_name"] label').text()).toContain('Name')
		expect(wrapper.find('[data-field="f_kind"] legend').classes()).toContain('af-sr-only')
		expect(wrapper.find('[data-field="f_agree"] .af-checkbox > span').classes()).toContain('af-sr-only')
		expect(wrapper.find('[data-field="f_story"] label').classes()).toContain('af-sr-only')
	})

	it('still honours the older hideLabel flag', () => {
		const wrapper = render({ labelPosition: 'inline' }, { f_name: { type: 'text', label: 'Name', hideLabel: true } })
		expect(position(wrapper, 'f_name')).toBe('hidden')
		expect(wrapper.find('[data-field="f_name"] label').classes()).toContain('af-sr-only')
	})

	it('keeps the label tied to its input in every position', () => {
		for (const labelPosition of ['above', 'inline', 'hidden'] as const) {
			const wrapper = render({ labelPosition }, { f_name: { type: 'text', label: 'Name' } })
			const label = wrapper.find('[data-field="f_name"] label')
			expect(label.attributes('for')).toBe(wrapper.find('[data-field="f_name"] input').attributes('id'))
		}
	})
})

describe('required indicator', () => {
	const fields: Record<string, FormField> = { f_name: { type: 'text', label: 'Name', required: true } }

	it('shows an asterisk by default', () => {
		expect(render({}, fields).find('label').text()).toContain('*')
	})

	it('can be hidden, while the control stays marked required', () => {
		const wrapper = render({ hideRequiredIndicator: true }, fields)
		expect(wrapper.find('label').text()).not.toContain('*')
		expect(wrapper.find('input').attributes('aria-required')).toBe('true')
	})
})

describe('text alignment of headings and paragraphs', () => {
	const fields: Record<string, FormField> = {
		f_head: { type: 'heading', text: 'Hello', level: 2, align: 'center' },
		f_para: { type: 'paragraph', text: 'Some text', align: 'end' },
		f_plain: { type: 'heading', text: 'Plain', level: 3 },
	}

	it('aligns what asks for it and leaves the rest alone', () => {
		const wrapper = render({}, fields)
		expect(wrapper.find('h2').attributes('style')).toContain('text-align: center')
		expect(wrapper.find('.af-markdown').attributes('style')).toContain('text-align: end')
		expect(wrapper.find('h3').attributes('style') ?? '').not.toContain('text-align')
	})
})
