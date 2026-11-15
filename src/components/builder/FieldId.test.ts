// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DraftDefinition, FormAction, OwnerForm } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { addField, emptyDefinition } from '../../builder/definition.ts'
import BuilderView from './BuilderView.vue'
import FieldProperties from './FieldProperties.vue'

const getFormMock = vi.fn()
const saveDraftMock = vi.fn()
vi.mock('../../api/settings.ts', () => {
	const open = { canCreate: true, allowedActionTypes: ['message', 'redirect', 'email', 'webhook', 'tables'], maxActions: 10 }
	return { OPEN_POLICY: open, fetchUserPolicy: () => Promise.resolve(open) }
})

vi.mock('../../api/forms.ts', () => ({
	getForm: (...args: unknown[]) => getFormMock(...args),
	saveDraft: (...args: unknown[]) => saveDraftMock(...args),
	publishForm: vi.fn(),
	deleteForm: vi.fn(),
	createWebhookSecret: vi.fn(),
	publicFormUrl: (token: string) => 'http://nc.test/f/' + token,
}))

function two(): { def: DraftDefinition, a: string, b: string } {
	let def = emptyDefinition('T')
	const a = addField(def, 'text', { kind: 'row', index: 0 })
	def = a.definition
	const b = addField(def, 'email', { kind: 'row', index: 1 })
	return { def: b.definition, a: a.fieldId, b: b.fieldId }
}

function mountProps(def: DraftDefinition, fieldId: string, extra: Record<string, unknown> = {}) {
	return mount(FieldProperties, { props: { definition: def, fieldId, errorCodes: [], ...extra } })
}

/** The Field ID input: the one that holds the field's current id. */
function idField(wrapper: ReturnType<typeof mount>, fieldId: string) {
	return wrapper.findAll('input').find((input) => (input.element as HTMLInputElement).value === fieldId)!
}

const changeButton = (wrapper: ReturnType<typeof mount>) => wrapper.findAll('button').find((b) => b.text() === 'Change ID')!

describe('Field ID editor', () => {
	it('starts on the current id with nothing to change', () => {
		const { def, a } = two()
		const wrapper = mountProps(def, a)
		expect((idField(wrapper, a).element as HTMLInputElement).value).toBe(a)
		expect(changeButton(wrapper).attributes('disabled')).toBeDefined()
	})

	it('explains why an id cannot be used, and enables the button for a good one', async () => {
		const { def, a, b } = two()
		const wrapper = mountProps(def, a, { retiredIds: ['old_field'] })
		const input = idField(wrapper, a)

		await input.setValue('Bad-ID')
		expect(wrapper.text()).toContain('Use 3 to 32 lowercase letters')
		expect(changeButton(wrapper).attributes('disabled')).toBeDefined()

		await input.setValue(b)
		expect(wrapper.text()).toContain('Another field already uses this ID')
		expect(changeButton(wrapper).attributes('disabled')).toBeDefined()

		await input.setValue('old_field')
		expect(wrapper.text()).toContain('cannot be used again')
		expect(changeButton(wrapper).attributes('disabled')).toBeDefined()

		await input.setValue('customer_name')
		expect(wrapper.text()).not.toContain('Use 3 to 32')
		expect(changeButton(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('emits the new id from the button and from Enter, and never for an invalid one', async () => {
		const { def, a } = two()
		const wrapper = mountProps(def, a)
		const input = idField(wrapper, a)

		await input.setValue('customer_name')
		await changeButton(wrapper).trigger('click')
		await input.trigger('keydown.enter')
		expect(wrapper.emitted('rename')).toEqual([['customer_name'], ['customer_name']])

		await input.setValue('X')
		await input.trigger('keydown.enter')
		expect(wrapper.emitted('rename')).toHaveLength(2)
	})

	it('warns that receivers will see the change only when the form is published', async () => {
		const { def, a } = two()
		const draftOnly = mountProps(def, a, { published: false })
		await idField(draftOnly, a).setValue('customer_name')
		expect(draftOnly.text()).not.toContain('This form is published')

		const live = mountProps(def, a, { published: true })
		expect(live.text()).not.toContain('This form is published')
		await idField(live, a).setValue('customer_name')
		expect(live.text()).toContain('This form is published')
		expect(live.text()).toContain('keep the old one')
	})
})

describe('renaming through the builder', () => {
	function ownerForm(draft: DraftDefinition, extra: Partial<OwnerForm> = {}): OwnerForm {
		return { id: 1, title: draft.title, description: null, status: 0, publicToken: null, latestVersionId: null, createdAt: 1, updatedAt: 1, draft, hasUnpublishedChanges: true, retiredIds: [], ...extra }
	}

	beforeEach(() => {
		vi.useFakeTimers()
		saveDraftMock.mockReset().mockImplementation(async (_id: number, definition: DraftDefinition) => ({ form: ownerForm(definition), errors: [] }))
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('renames a field everywhere, keeps it selected, and autosaves the result', async () => {
		const { def, a, b } = two()
		def.rules = [{ id: 'rule_one', effect: 'show', targets: [a], when: { all: [{ field: b, op: 'not_empty' }] } }]
		def.actions = [{ id: 'act_mail', type: 'email', to: { field: b }, subject: `Hi {${a}}`, body: 'b' }] as FormAction[]
		getFormMock.mockReset().mockResolvedValue(ownerForm(def))

		const wrapper = mount(BuilderView, { props: { formId: 1 }, attachTo: document.body })
		await flushPromises()
		wrapper.findComponent({ name: 'BuilderCanvas' }).vm.$emit('select', b)
		await flushPromises()
		wrapper.findComponent({ name: 'FieldProperties' }).vm.$emit('rename', 'customer_email')
		await flushPromises()

		// Still editing the same field, under its new id.
		expect(wrapper.findComponent({ name: 'FieldProperties' }).props('fieldId')).toBe('customer_email')

		await vi.advanceTimersByTimeAsync(800)
		await flushPromises()
		const saved = saveDraftMock.mock.calls[0][1] as DraftDefinition
		expect(Object.keys(saved.fields)).toEqual([a, 'customer_email'])
		expect(saved.rules[0].when).toEqual({ all: [{ field: 'customer_email', op: 'not_empty' }] })
		expect(saved.actions[0]).toMatchObject({ to: { field: 'customer_email' }, subject: `Hi {${a}}` })
		expect(JSON.stringify(saved)).not.toContain(b)
		wrapper.unmount()
	})

	it('ignores a rename to a retired id', async () => {
		const { def, a } = two()
		getFormMock.mockReset().mockResolvedValue(ownerForm(def, { retiredIds: ['old_field'], latestVersionId: 3 }))
		const wrapper = mount(BuilderView, { props: { formId: 1 }, attachTo: document.body })
		await flushPromises()
		wrapper.findComponent({ name: 'BuilderCanvas' }).vm.$emit('select', a)
		await flushPromises()
		wrapper.findComponent({ name: 'FieldProperties' }).vm.$emit('rename', 'old_field')
		await vi.advanceTimersByTimeAsync(800)
		await flushPromises()
		expect(saveDraftMock).not.toHaveBeenCalled()
		expect(wrapper.findComponent({ name: 'FieldProperties' }).props('fieldId')).toBe(a)
		expect(wrapper.findComponent({ name: 'FieldProperties' }).props('published')).toBe(true)
		wrapper.unmount()
	})

	it('keeps the same panel while the id follows the label, so typing does not lose focus', async () => {
		const def = emptyDefinition('T')
		const added = addField(def, 'text', { kind: 'row', index: 0 })
		getFormMock.mockReset().mockResolvedValue(ownerForm(added.definition))
		const wrapper = mount(BuilderView, { props: { formId: 1 }, attachTo: document.body })
		await flushPromises()
		wrapper.findComponent({ name: 'BuilderCanvas' }).vm.$emit('select', added.fieldId)
		await flushPromises()
		const before = wrapper.findComponent({ name: 'FieldProperties' }).vm.$el
		wrapper.findComponent({ name: 'FieldProperties' }).vm.$emit('update', { label: 'First Name' })
		await flushPromises()
		const panel = wrapper.findComponent({ name: 'FieldProperties' })
		expect(panel.props('fieldId')).toBe('f_first_name')
		expect(panel.vm.$el).toBe(before)
		wrapper.unmount()
	})
})
