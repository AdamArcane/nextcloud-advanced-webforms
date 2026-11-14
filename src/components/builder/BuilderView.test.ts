// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DraftDefinition, OwnerForm } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { emptyDefinition } from '../../builder/definition.ts'
import BuilderView from './BuilderView.vue'

const getFormMock = vi.fn()
const saveDraftMock = vi.fn()
const publishMock = vi.fn()
vi.mock('../../api/forms.ts', () => ({
	getForm: (...args: unknown[]) => getFormMock(...args),
	saveDraft: (...args: unknown[]) => saveDraftMock(...args),
	publishForm: (...args: unknown[]) => publishMock(...args),
	deleteForm: vi.fn(),
	publicFormUrl: (token: string) => 'http://nc.test/f/' + token,
}))

function ownerForm(draft: DraftDefinition, extra: Partial<OwnerForm> = {}): OwnerForm {
	return {
		id: 1,
		title: draft.title,
		description: null,
		status: 0,
		publicToken: null,
		latestVersionId: null,
		createdAt: 1,
		updatedAt: 1,
		draft,
		hasUnpublishedChanges: true,
		...extra,
	}
}

function mountView(view: 'edit' | 'settings' = 'edit') {
	return mount(BuilderView, { props: { formId: 1, view }, attachTo: document.body })
}

describe('BuilderView', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		getFormMock.mockReset().mockResolvedValue(ownerForm(emptyDefinition('Survey')))
		saveDraftMock.mockReset().mockImplementation(async (_id: number, definition: DraftDefinition) => ({
			form: ownerForm(definition),
			errors: [],
		}))
		publishMock.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('shows the title and the empty-canvas prompt once loaded', async () => {
		const wrapper = mountView()
		await flushPromises()
		expect(wrapper.text()).toContain('Survey')
		expect(wrapper.text()).toContain('Start your form')
		wrapper.unmount()
	})

	it('keeps form-wide settings and actions off the field sidebar', async () => {
		const edit = mountView()
		await flushPromises()
		expect(edit.findComponent({ name: 'FormProperties' }).exists()).toBe(false)
		expect(edit.findComponent({ name: 'ActionsPanel' }).exists()).toBe(false)
		edit.unmount()

		const settings = mountView('settings')
		await flushPromises()
		expect(settings.findComponent({ name: 'FormProperties' }).exists()).toBe(true)
		expect(settings.findComponent({ name: 'ActionsPanel' }).exists()).toBe(true)
		expect(settings.findComponent({ name: 'BuilderCanvas' }).exists()).toBe(false)
		settings.unmount()
	})

	it('shows a not-found message when the form cannot be loaded', async () => {
		getFormMock.mockRejectedValue(new Error('nope'))
		const wrapper = mountView()
		await flushPromises()
		expect(wrapper.text()).toContain('Form not found')
		wrapper.unmount()
	})

	it('autosaves once after a burst of edits, with the latest draft', async () => {
		const wrapper = mountView('settings')
		await flushPromises()

		const form = wrapper.findComponent({ name: 'FormProperties' })
		expect(form.exists()).toBe(true)
		form.vm.$emit('title', 'Su')
		form.vm.$emit('title', 'Sur')
		form.vm.$emit('title', 'Survey 2')
		await flushPromises()
		expect(saveDraftMock).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Unsaved changes')

		await vi.advanceTimersByTimeAsync(800)
		await flushPromises()
		expect(saveDraftMock).toHaveBeenCalledTimes(1)
		expect(saveDraftMock.mock.calls[0][1].title).toBe('Survey 2')
		expect(wrapper.text()).toContain('Saved')
		wrapper.unmount()
	})

	it('sends an edit made while a save is running afterwards, not instead', async () => {
		let release: (() => void) | undefined
		saveDraftMock.mockImplementationOnce((_id: number, definition: DraftDefinition) => new Promise((resolve) => {
			release = () => resolve({ form: ownerForm(definition), errors: [] })
		}))
		const wrapper = mountView('settings')
		await flushPromises()
		const form = wrapper.findComponent({ name: 'FormProperties' })

		form.vm.$emit('title', 'First')
		await vi.advanceTimersByTimeAsync(800)
		expect(saveDraftMock).toHaveBeenCalledTimes(1)

		form.vm.$emit('title', 'Second')
		await vi.advanceTimersByTimeAsync(800)
		// Still waiting on the first response: no overlapping request.
		expect(saveDraftMock).toHaveBeenCalledTimes(1)

		release!()
		await flushPromises()
		await vi.advanceTimersByTimeAsync(0)
		await flushPromises()
		expect(saveDraftMock).toHaveBeenCalledTimes(2)
		expect(saveDraftMock.mock.calls[1][1].title).toBe('Second')
		expect(wrapper.text()).toContain('Saved')
		wrapper.unmount()
	})

	it('reports a failed save and keeps the edit', async () => {
		saveDraftMock.mockRejectedValue(new Error('offline'))
		const wrapper = mountView('settings')
		await flushPromises()
		wrapper.findComponent({ name: 'FormProperties' }).vm.$emit('title', 'X')
		await vi.advanceTimersByTimeAsync(800)
		await flushPromises()
		expect(wrapper.text()).toContain('Could not save')
		expect(wrapper.text()).toContain('X')
		wrapper.unmount()
	})

	it('adds a field from the canvas and selects it', async () => {
		const wrapper = mountView()
		await flushPromises()
		wrapper.findComponent({ name: 'BuilderCanvas' }).vm.$emit('add', 'text', 0)
		await flushPromises()
		expect(wrapper.findAllComponents({ name: 'FieldCard' })).toHaveLength(1)
		expect(wrapper.findComponent({ name: 'FieldProperties' }).exists()).toBe(true)
		await vi.advanceTimersByTimeAsync(800)
		await flushPromises()
		const saved = saveDraftMock.mock.calls[0][1] as DraftDefinition
		expect(Object.values(saved.fields)).toHaveLength(1)
		expect(saved.pages[0].rows).toHaveLength(1)
		wrapper.unmount()
	})

	it('publishes what is on screen and surfaces validation errors', async () => {
		const { ApiError } = await import('../../api/http.ts')
		publishMock.mockRejectedValue(new ApiError(422, 'validation', [{ code: 'field_label_invalid', path: 'fields.f_abc123.label' }] as never))
		const wrapper = mountView('settings')
		await flushPromises()
		wrapper.findComponent({ name: 'FormProperties' }).vm.$emit('title', 'Changed')
		const publish = wrapper.findAll('button').find((button) => button.text().includes('Publish'))!
		await publish.trigger('click')
		await flushPromises()
		// The pending edit was saved before publishing.
		expect(saveDraftMock).toHaveBeenCalled()
		expect(saveDraftMock.mock.calls.at(-1)![1].title).toBe('Changed')
		expect(wrapper.text()).toContain('Fix the highlighted problems')
		wrapper.unmount()
	})
})
