// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DraftDefinition, OwnerForm } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/http.ts'
import { emptyDefinition } from '../../builder/definition.ts'
import BuilderView from './BuilderView.vue'

const getForm = vi.hoisted(() => vi.fn())
const saveDraft = vi.hoisted(() => vi.fn())
const publishForm = vi.hoisted(() => vi.fn())

vi.mock('../../api/settings.ts', () => {
	const open = { canCreate: true, allowedActionTypes: ['message', 'redirect', 'email', 'webhook', 'tables', 'files'], maxActions: 10 }
	return { OPEN_POLICY: open, fetchUserPolicy: () => Promise.resolve(open) }
})

vi.mock('../../api/forms.ts', () => ({
	getForm: (...args: unknown[]) => getForm(...args),
	saveDraft: (...args: unknown[]) => saveDraft(...args),
	publishForm: (...args: unknown[]) => publishForm(...args),
	deleteForm: vi.fn(),
	setFormArchived: vi.fn(),
	setFormShare: vi.fn(),
	publicFormUrl: (token: string) => 'http://nc.test/f/' + token,
	embedFormUrl: (token: string) => 'http://nc.test/f/' + token + '/embed',
}))

function form(title: string, revision: number): OwnerForm {
	const draft = emptyDefinition(title)
	return { id: 1, title, description: null, status: 0, publicToken: 'tok', latestVersionId: null, createdAt: 1, updatedAt: 1, draft, hasUnpublishedChanges: true, role: 'editor', revision }
}

function conflictError(): ApiError {
	const theirs = emptyDefinition('Theirs')
	return new ApiError(409, 'draft_conflict', [], null, { error: 'draft_conflict', revision: 7, draft: theirs, savedBy: 'Olga Owner', savedAt: 1700000000 })
}

async function edited() {
	const wrapper = mount(BuilderView, { props: { formId: 1 }, attachTo: document.body })
	await flushPromises()
	wrapper.findComponent({ name: 'BuilderCanvas' }).vm.$emit('add', 'text', 0, 0)
	await vi.advanceTimersByTimeAsync(900)
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.useFakeTimers()
	getForm.mockReset().mockResolvedValue(form('Mine', 3))
	publishForm.mockReset()
	saveDraft.mockReset().mockImplementation(async (_id: number, saved: DraftDefinition, base: number) => ({ form: { ...form('Mine', base + 1), draft: saved }, errors: [] }))
})

afterEach(() => {
	vi.useRealTimers()
	document.body.innerHTML = ''
})

describe('two people editing at once (docs/SCHEMA.md section 22.6)', () => {
	it('sends the revision it loaded, then the one the last save returned', async () => {
		const wrapper = await edited()
		expect(saveDraft.mock.calls[0][2]).toBe(3)
		wrapper.findComponent({ name: 'BuilderCanvas' }).vm.$emit('add', 'text', 0, 0)
		await vi.advanceTimersByTimeAsync(900)
		await flushPromises()
		expect(saveDraft.mock.calls[1][2]).toBe(4)
		wrapper.unmount()
	})

	it('stops saving on a conflict, says who changed it, and does not publish', async () => {
		saveDraft.mockImplementation(() => Promise.reject(conflictError()))
		const wrapper = await edited()
		expect(wrapper.find('[data-test="draft-conflict"]').text()).toContain('Olga Owner changed this form')
		const calls = saveDraft.mock.calls.length
		wrapper.findComponent({ name: 'BuilderCanvas' }).vm.$emit('add', 'text', 0, 0)
		await vi.advanceTimersByTimeAsync(2000)
		expect(saveDraft.mock.calls.length).toBe(calls)
		expect(publishForm).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it('Keep mine saves again over the stored revision', async () => {
		saveDraft.mockImplementationOnce(() => Promise.reject(conflictError()))
		const wrapper = await edited()
		await wrapper.find('[data-test="conflict-keep-mine"]').trigger('click')
		await flushPromises()
		expect(saveDraft.mock.calls.at(-1)![2]).toBe(7)
		expect(wrapper.find('[data-test="draft-conflict"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('Use their version loads the stored form and drops the unsaved edits', async () => {
		saveDraft.mockImplementationOnce(() => Promise.reject(conflictError()))
		const wrapper = await edited()
		getForm.mockResolvedValue(form('Theirs', 7))
		await wrapper.find('[data-test="conflict-use-theirs"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-test="draft-conflict"]').exists()).toBe(false)
		expect(getForm).toHaveBeenLastCalledWith(1)
		wrapper.unmount()
	})
})
