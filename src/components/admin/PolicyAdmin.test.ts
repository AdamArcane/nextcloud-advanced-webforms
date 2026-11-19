// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PolicyAdminSettings, PolicyInput } from '../../api/admin.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/http.ts'
import PolicyAdmin from './PolicyAdmin.vue'

const ALL = ['email', 'webhook', 'message', 'redirect', 'tables']
let current: PolicyAdminSettings
let loadFails = false
let previewDue = 0
const saves: PolicyInput[] = []
let confirmNeeded: number | null = null

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../api/admin.ts', () => ({
	fetchPolicy: () => (loadFails ? Promise.reject(new Error('x')) : Promise.resolve(current)),
	fetchRetentionPreview: () => Promise.resolve(previewDue),
	searchGroups: () => Promise.resolve([{ id: 'staff', displayName: 'Staff' }]),
	savePolicy: (input: PolicyInput) => {
		saves.push(input)
		if (confirmNeeded !== null && !input.confirmDelete) {
			return new Promise((_resolve, reject) => setTimeout(() => reject(new ApiError(409, 'confirmation_required', [], null, { due: confirmNeeded })), 0))
		}
		current = { ...current, actionTypes: input.actionTypes, retentionDays: input.retentionDays, maxResponses: input.maxResponses, maxActions: input.maxActions, creatorGroups: input.creatorGroups.map((id) => ({ id, displayName: id })), sharing: input.sharing }
		return Promise.resolve(current)
	},
}))

function policy(extra: Partial<PolicyAdminSettings> = {}): PolicyAdminSettings {
	return { actionTypes: ALL, allActionTypes: ALL, retentionDays: 0, maxResponses: 0, maxActions: 10, maxActionsCeiling: 10, creatorGroups: [], sharing: true, ...extra }
}

async function mountIt() {
	const wrapper = mount(PolicyAdmin)
	await flushPromises()
	return wrapper
}

function button(wrapper: Awaited<ReturnType<typeof mountIt>>, text: string) {
	return wrapper.findAll('button').find((b) => b.text() === text)!
}

describe('PolicyAdmin', () => {
	beforeEach(() => {
		current = policy()
		loadFails = false
		previewDue = 0
		confirmNeeded = null
		saves.length = 0
	})

	it('starts with everything open and nothing deleted', async () => {
		const wrapper = await mountIt()
		const text = wrapper.text()
		expect(text).toContain('Allowed actions')
		expect(text).toContain('Responses are kept until their owner deletes them.')
		expect(text).toContain('Everyone')
		expect(text).not.toContain('Days to keep a response')
	})

	it('says so when the settings cannot be loaded', async () => {
		loadFails = true
		const wrapper = await mountIt()
		expect(wrapper.text()).toContain('Could not load the settings.')
	})

	it('saves the policy as it is shown', async () => {
		const wrapper = await mountIt()
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()
		expect(saves).toEqual([{ actionTypes: ALL, retentionDays: 0, maxResponses: 0, maxActions: 10, creatorGroups: [], sharing: true, confirmDelete: false }])
	})

	it('shows sharing as on and saves it as off when switched off (docs/SCHEMA.md section 22.8)', async () => {
		const wrapper = await mountIt()
		expect(wrapper.text()).toContain('Sharing of forms')
		const toggle = wrapper.findAll('input[type="checkbox"]').find((box) => box.element.closest('.checkbox-content, label, span')?.textContent?.includes('Owners can share'))!
		expect((toggle.element as HTMLInputElement).checked).toBe(true)
		await toggle.setValue(false)
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()
		expect(saves.at(-1)?.sharing).toBe(false)
	})

	it('shows the retention warning and how many responses would go', async () => {
		current = policy({ retentionDays: 30 })
		previewDue = 7
		const wrapper = await mountIt()
		expect(wrapper.text()).toContain('Days to keep a response')
		expect(wrapper.text()).toContain('deleted for good')
		expect(wrapper.text()).toContain('7 existing responses are older than this and will be deleted.')
	})

	it('asks for confirmation before deleting existing responses, and only then saves for real', async () => {
		current = policy({ retentionDays: 30 })
		confirmNeeded = 12
		const wrapper = await mountIt()
		await button(wrapper, 'Save').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 5))
		await flushPromises()
		expect(wrapper.text()).toContain('Saving will delete 12 existing responses older than 30 days')
		expect(saves).toHaveLength(1)
		await button(wrapper, 'Delete them and save').trigger('click')
		await flushPromises()
		expect(saves).toHaveLength(2)
		expect(saves[1].confirmDelete).toBe(true)
		expect(wrapper.text()).not.toContain('Saving will delete')
	})

	it('can be cancelled without saving anything', async () => {
		current = policy({ retentionDays: 30 })
		confirmNeeded = 3
		const wrapper = await mountIt()
		await button(wrapper, 'Save').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 5))
		await flushPromises()
		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.text()).not.toContain('Saving will delete')
		expect(saves).toHaveLength(1)
	})

	it('refuses to save an invalid number', async () => {
		current = policy({ retentionDays: 30 })
		const wrapper = await mountIt()
		const days = wrapper.findAll('input').find((input) => input.attributes('max') === '3650')!
		await days.setValue('0')
		expect(button(wrapper, 'Save').attributes('disabled')).toBeDefined()
	})

	it('needs a group when creation is limited to groups', async () => {
		current = policy({ creatorGroups: [{ id: 'staff', displayName: 'Staff' }] })
		const wrapper = await mountIt()
		expect(wrapper.text()).toContain('Staff')
		expect(button(wrapper, 'Save').attributes('disabled')).toBeUndefined()
		expect(wrapper.text()).toContain('Forms that already exist keep working')
	})
})
