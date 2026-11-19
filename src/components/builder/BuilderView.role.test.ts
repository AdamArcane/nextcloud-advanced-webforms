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

vi.mock('../../api/settings.ts', () => {
	const open = { canCreate: true, allowedActionTypes: ['message', 'redirect', 'email', 'webhook', 'tables', 'files'], maxActions: 10 }
	return { OPEN_POLICY: open, fetchUserPolicy: () => Promise.resolve(open) }
})

vi.mock('../../api/forms.ts', () => ({
	getForm: (...args: unknown[]) => getForm(...args),
	saveDraft: (...args: unknown[]) => saveDraft(...args),
	publishForm: vi.fn(),
	deleteForm: vi.fn(),
	setFormArchived: vi.fn(),
	setFormShare: vi.fn(),
	listCollaborators: vi.fn().mockResolvedValue({ owner: { id: 'olga', displayName: 'Olga Owner' }, shares: [] }),
	findSharees: vi.fn().mockResolvedValue([]),
	shareForm: vi.fn(),
	unshareForm: vi.fn(),
	publicFormUrl: (token: string) => 'http://nc.test/f/' + token,
	embedFormUrl: (token: string) => 'http://nc.test/f/' + token + '/embed',
}))

function definition(): DraftDefinition {
	const base = emptyDefinition('Intake')
	return {
		...base,
		fields: {
			f_cv: { type: 'file', label: 'CV', maxFiles: 1, maxSizeMb: 10, accept: ['documents'], audience: 'anyone', storage: 'files', folder: 'Hiring/CVs' },
			f_photo: { type: 'file', label: 'Photo', maxFiles: 1, maxSizeMb: 10, accept: ['images'], audience: 'anyone', storage: 'app' },
		},
		pages: [{ id: 'p_1', title: null, rows: [
			{ id: 'r_1', cells: [{ id: 'c_1', span: 12, field: 'f_cv' }] },
			{ id: 'r_2', cells: [{ id: 'c_2', span: 12, field: 'f_photo' }] },
		] }],
		actions: [
			{ id: 'a_csv', type: 'files', path: 'Hiring/answers.csv' } as never,
			{ id: 'a_mail', type: 'email', to: 'hr@example.com', subject: 'New', body: 'Hi' } as never,
		],
	}
}

function form(role: 'owner' | 'editor', draft: DraftDefinition): OwnerForm {
	return { id: 1, title: draft.title, description: null, status: 0, publicToken: 'tok', latestVersionId: null, createdAt: 1, updatedAt: 1, draft, hasUnpublishedChanges: true, role, ownerId: 'olga', ownerName: 'Olga Owner' }
}

async function mountAs(role: 'owner' | 'editor', view: 'edit' | 'settings' = 'edit', draft = definition()) {
	getForm.mockResolvedValue(form(role, draft))
	const wrapper = mount(BuilderView, { props: { formId: 1, view }, attachTo: document.body })
	await flushPromises()
	return wrapper
}

async function openSettings(wrapper: Awaited<ReturnType<typeof mountAs>>, label: string): Promise<void> {
	await wrapper.findAll('[role="radio"]').find((button) => button.text().includes(label))!.trigger('click')
	await flushPromises()
}

beforeEach(() => {
	vi.useFakeTimers()
	getForm.mockReset()
	saveDraft.mockReset().mockImplementation(async (_id: number, saved: DraftDefinition) => ({ form: form('editor', saved), errors: [] }))
})

afterEach(() => {
	vi.useRealTimers()
	document.body.innerHTML = ''
})

describe('what an owner has that an editor does not (docs/SCHEMA.md section 22.1)', () => {
	it('lets the owner delete the form and decide who it is shared with', async () => {
		const wrapper = await mountAs('owner', 'settings')
		expect(wrapper.text()).toContain('Delete form')
		await openSettings(wrapper, 'Sharing')
		expect(wrapper.findComponent({ name: 'CollaboratorsPanel' }).exists()).toBe(true)
		wrapper.unmount()
	})

	it('keeps Delete form and Collaborators from an editor, who still has the link and the rest', async () => {
		const wrapper = await mountAs('editor', 'settings')
		expect(wrapper.text()).not.toContain('Delete form')
		expect(wrapper.text()).toContain('Archive form')
		await openSettings(wrapper, 'Sharing')
		expect(wrapper.findComponent({ name: 'CollaboratorsPanel' }).exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'SharePanel' }).exists()).toBe(true)
		wrapper.unmount()
	})
})

describe('what an editor cannot point at in the owner\'s Files and Tables (section 22.5)', () => {
	it('shows the files action read only, with the reason, and does not offer Remove', async () => {
		const wrapper = await mountAs('editor', 'settings')
		await openSettings(wrapper, 'Actions')
		const cards = wrapper.findAllComponents({ name: 'ActionCard' })
		expect(cards).toHaveLength(2)
		expect(cards[0].props('ownerOnly')).toBe(true)
		// The email action is the editor's to change.
		expect(cards[1].props('ownerOnly')).toBe(true)
		await cards[0].find('button').trigger('click')
		await flushPromises()
		expect(cards[0].text()).toContain('Only the owner of this form can change this action')
		expect(cards[0].find('.af-action__body').attributes('inert')).toBeDefined()
		await cards[1].find('button').trigger('click')
		await flushPromises()
		expect(cards[1].find('.af-action__body').attributes('inert')).toBeUndefined()
		wrapper.unmount()
	})

	it('does not offer an editor the files or tables actions to add', async () => {
		const wrapper = await mountAs('editor', 'settings')
		await openSettings(wrapper, 'Actions')
		expect(wrapper.findComponent({ name: 'AddActionMenu' }).props('types')).toEqual(['message', 'redirect', 'email', 'webhook'])
		wrapper.unmount()
	})

	it('offers the owner every action', async () => {
		const wrapper = await mountAs('owner', 'settings')
		await openSettings(wrapper, 'Actions')
		expect(wrapper.findComponent({ name: 'AddActionMenu' }).props('types')).toEqual(['message', 'redirect', 'email', 'webhook', 'tables', 'files'])
		wrapper.unmount()
	})

	it('keeps a file field an editor adds in the app, and a field an owner adds as it is', async () => {
		const editor = await mountAs('editor')
		editor.findComponent({ name: 'BuilderCanvas' }).vm.$emit('add', 'file', 0, 0)
		await vi.advanceTimersByTimeAsync(900)
		await flushPromises()
		const saved = saveDraft.mock.calls.at(-1)![1] as DraftDefinition
		const added = Object.entries(saved.fields).filter(([id]) => id !== 'f_cv' && id !== 'f_photo')
		expect(added).toHaveLength(1)
		expect(added[0][1].storage).toBe('app')
		editor.unmount()

		saveDraft.mockClear()
		const owner = await mountAs('owner')
		owner.findComponent({ name: 'BuilderCanvas' }).vm.$emit('add', 'file', 0, 0)
		await vi.advanceTimersByTimeAsync(900)
		await flushPromises()
		const ownerSaved = saveDraft.mock.calls.at(-1)![1] as DraftDefinition
		const ownerAdded = Object.entries(ownerSaved.fields).filter(([id]) => id !== 'f_cv' && id !== 'f_photo')
		expect(ownerAdded[0][1].storage).toBe('files')
		owner.unmount()
	})

	it('does not let an editor delete a field that is kept in the owner\'s Files, and says why', async () => {
		const wrapper = await mountAs('editor')
		wrapper.findComponent({ name: 'BuilderCanvas' }).vm.$emit('remove', 'f_cv')
		await flushPromises()
		expect(document.body.textContent).not.toContain('Delete this field?')
		expect(wrapper.text()).toContain('Only the owner of this form can change where files are saved')
		wrapper.unmount()
	})

	it('lets an editor delete a field that is kept in the app', async () => {
		const wrapper = await mountAs('editor')
		wrapper.findComponent({ name: 'BuilderCanvas' }).vm.$emit('remove', 'f_photo')
		await flushPromises()
		expect(document.body.textContent).toContain('Delete this field?')
		wrapper.unmount()
	})

	it('says what happened when the server refuses a change to where files go', async () => {
		saveDraft.mockImplementation(() => Promise.reject(new ApiError(422, 'validation', [{ code: 'owner_only_destination', path: 'fields.f_cv' }] as never)))
		const wrapper = await mountAs('editor')
		wrapper.findComponent({ name: 'BuilderCanvas' }).vm.$emit('add', 'text', 0, 0)
		await vi.advanceTimersByTimeAsync(900)
		await flushPromises()
		expect(wrapper.text()).toContain('Only the owner of this form can change where files are saved or use Tables')
		wrapper.unmount()
	})

describe('a form that passed to a new owner (section 22.7)', () => {
	it('says it is closed until the places it saves to are checked and published again', async () => {
		getForm.mockResolvedValue({ ...form('owner', definition()), destinationsPending: true })
		const wrapper = mount(BuilderView, { props: { formId: 1 }, attachTo: document.body })
		await flushPromises()
		expect(wrapper.find('[data-test="destinations-pending"]').text()).toContain('publish to open it again')
		wrapper.unmount()
	})
})
})
