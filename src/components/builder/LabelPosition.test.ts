// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DraftDefinition } from '../../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { addField, emptyDefinition, updateField } from '../../builder/definition.ts'
import FieldCard from './FieldCard.vue'
import FieldProperties from './FieldProperties.vue'
import FormProperties from './FormProperties.vue'

function withField(type: string, patch: Record<string, unknown> = {}, settings: Record<string, unknown> = {}): { def: DraftDefinition, id: string } {
	const added = addField(emptyDefinition('T'), type, { kind: 'row', index: 0 })
	const def = updateField(added.definition, added.fieldId, patch)
	def.settings = settings
	return { def, id: added.fieldId }
}

function segment(wrapper: ReturnType<typeof mount>, group: string, label: string) {
	return wrapper.find(`[role="radiogroup"][aria-label="${group}"]`).findAll('[role="radio"]').find((button) => button.text() === label)!
}

describe('label position in the field editor', () => {
	it('follows the form by default', () => {
		const { def, id } = withField('text')
		const wrapper = mount(FieldProperties, { props: { section: 'general' as const, definition: def, fieldId: id, errorCodes: [] } })
		expect(segment(wrapper, 'Label position', 'Form').attributes('aria-checked')).toBe('true')
		expect(wrapper.text()).toContain('Following the form setting: above the field.')
	})

	it('explains what the form setting means for this field', () => {
		const { def, id } = withField('text', {}, { labelPosition: 'inline' })
		const wrapper = mount(FieldProperties, { props: { section: 'general' as const, definition: def, fieldId: id, errorCodes: [] } })
		expect(wrapper.text()).toContain('beside the field')
	})

	it('says when a type cannot go beside and the label stays above', () => {
		const { def, id } = withField('textarea', {}, { labelPosition: 'inline' })
		const wrapper = mount(FieldProperties, { props: { section: 'general' as const, definition: def, fieldId: id, errorCodes: [] } })
		expect(wrapper.text()).toContain('stays above this kind of field')
	})

	it('emits the chosen position and clears the older hideLabel flag', async () => {
		const { def, id } = withField('text', { hideLabel: true })
		const wrapper = mount(FieldProperties, { props: { section: 'general' as const, definition: def, fieldId: id, errorCodes: [] } })
		// The older flag shows as Hidden.
		expect(segment(wrapper, 'Label position', 'Hidden').attributes('aria-checked')).toBe('true')
		await segment(wrapper, 'Label position', 'Beside').trigger('click')
		expect(wrapper.emitted('update')![0][0]).toEqual({ labelPosition: 'inline', hideLabel: undefined })
	})

	it('returns to the form default by removing the property', async () => {
		const { def, id } = withField('text', { labelPosition: 'above' })
		const wrapper = mount(FieldProperties, { props: { section: 'general' as const, definition: def, fieldId: id, errorCodes: [] } })
		await segment(wrapper, 'Label position', 'Form').trigger('click')
		expect(wrapper.emitted('update')![0][0]).toEqual({ labelPosition: undefined, hideLabel: undefined })
	})

	it('does not offer Beside for fields that cannot use it', () => {
		for (const type of ['textarea', 'radio', 'checkboxes', 'checkbox']) {
			const { def, id } = withField(type)
			const wrapper = mount(FieldProperties, { props: { section: 'general' as const, definition: def, fieldId: id, errorCodes: [] } })
			expect(segment(wrapper, 'Label position', 'Beside').attributes('disabled')).toBeDefined()
			expect(segment(wrapper, 'Label position', 'Above').attributes('disabled')).toBeUndefined()
		}
		const { def, id } = withField('email')
		const wrapper = mount(FieldProperties, { props: { section: 'general' as const, definition: def, fieldId: id, errorCodes: [] } })
		expect(segment(wrapper, 'Label position', 'Beside').attributes('disabled')).toBeUndefined()
	})

	it('does not show a label position for display fields', () => {
		const { def, id } = withField('paragraph')
		const wrapper = mount(FieldProperties, { props: { section: 'general' as const, definition: def, fieldId: id, errorCodes: [] } })
		expect(wrapper.text()).not.toContain('Label position')
	})
})

describe('label position for the whole form', () => {
	it('shows the current default and emits changes, leaving above as the absence of a setting', async () => {
		const def = emptyDefinition('T')
		const wrapper = mount(FormProperties, { props: { section: 'general' as const, definition: def, shareUrl: null } })
		const group = 'Label position for the whole form'
		expect(segment(wrapper, group, 'Above').attributes('aria-checked')).toBe('true')

		await segment(wrapper, group, 'Beside').trigger('click')
		await segment(wrapper, group, 'Hidden').trigger('click')
		await segment(wrapper, group, 'Above').trigger('click')
		expect(wrapper.emitted('settings')).toEqual([
			[{ labelPosition: 'inline' }],
			[{ labelPosition: 'hidden' }],
			[{ labelPosition: undefined }],
		])
	})

	it('reflects a saved default', () => {
		const def = emptyDefinition('T')
		def.settings = { labelPosition: 'inline' }
		const wrapper = mount(FormProperties, { props: { section: 'general' as const, definition: def, shareUrl: null } })
		expect(segment(wrapper, 'Label position for the whole form', 'Beside').attributes('aria-checked')).toBe('true')
	})
})

describe('canvas card', () => {
	const base = { selected: false, ruleCount: 0, errors: [] as string[] }

	it('shows the position respondents will see', () => {
		const field = { type: 'text', label: 'Name' }
		const inline = mount(FieldCard, { props: { ...base, field, labelPosition: 'inline' } })
		expect(inline.find('.af-card__body').classes()).toContain('af-card__body--inline')
		expect(inline.text()).toContain('Label beside')

		const hidden = mount(FieldCard, { props: { ...base, field, labelPosition: 'hidden' } })
		expect(hidden.text()).toContain('Label hidden')

		const above = mount(FieldCard, { props: { ...base, field, labelPosition: 'above' } })
		expect(above.find('.af-card__body').classes()).not.toContain('af-card__body--inline')
		expect(above.text()).not.toContain('Label beside')
	})
})

describe('file upload field in the builder', () => {
	it('is named File upload on its card, not a divider', () => {
		const { def, id } = withField('file')
		const wrapper = mount(FieldCard, { props: { field: def.fields[id], selected: false, ruleCount: 0, errors: [], labelPosition: 'above' as const } })
		expect(wrapper.text()).toContain('File upload')
		expect(wrapper.text()).not.toContain('Divider')
	})
})
