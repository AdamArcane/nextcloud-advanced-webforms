// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { MailAdminSettings } from '../../api/admin.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MailAdmin from './MailAdmin.vue'

let current: MailAdminSettings
let loadFails = false
const saves: MailAdminSettings[] = []

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../api/admin.ts', () => ({
	fetchMailSettings: () => (loadFails ? Promise.reject(new Error('x')) : Promise.resolve(current)),
	saveMailSettings: (input: MailAdminSettings) => {
		saves.push(input)
		current = input
		return Promise.resolve(current)
	},
}))

async function mountIt() {
	const wrapper = mount(MailAdmin)
	await flushPromises()
	return wrapper
}

function saveButton(wrapper: Awaited<ReturnType<typeof mountIt>>) {
	return wrapper.findAll('button').find((node) => node.text() === 'Save')!
}

beforeEach(() => {
	current = { replyToMode: 'none', replyToAddress: '', allowOverride: true, fromName: '' }
	loadFails = false
	saves.length = 0
})

describe('email settings for administrators', () => {
	it('starts from what is saved and saves it back unchanged', async () => {
		current = { replyToMode: 'owner', replyToAddress: '', allowOverride: false, fromName: 'Acme' }
		const wrapper = await mountIt()
		expect(wrapper.text()).toContain('The defaults above are always used.')
		await saveButton(wrapper).trigger('click')
		await flushPromises()
		expect(saves).toEqual([{ replyToMode: 'owner', replyToAddress: '', allowOverride: false, fromName: 'Acme' }])
	})

	it('asks for an address only for the one-address choice, and refuses a bad one', async () => {
		const wrapper = await mountIt()
		expect(wrapper.find('input[type="email"]').exists()).toBe(false)
		await wrapper.find('input[type="radio"][value="address"]').setValue(true)
		const address = wrapper.find('input[type="email"]')
		expect(address.exists()).toBe(true)
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()

		await address.setValue('not an address')
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()

		await address.setValue(' support@example.com ')
		await saveButton(wrapper).trigger('click')
		await flushPromises()
		expect(saves).toEqual([{ replyToMode: 'address', replyToAddress: 'support@example.com', allowOverride: true, fromName: '' }])
	})

	it('does not send a left-over address with another choice', async () => {
		current = { replyToMode: 'address', replyToAddress: 'support@example.com', allowOverride: true, fromName: '' }
		const wrapper = await mountIt()
		await wrapper.find('input[type="radio"][value="none"]').setValue(true)
		await saveButton(wrapper).trigger('click')
		await flushPromises()
		expect(saves[0]).toMatchObject({ replyToMode: 'none', replyToAddress: '' })
	})

	it('says so when the settings cannot be loaded', async () => {
		loadFails = true
		const wrapper = await mountIt()
		expect(wrapper.text()).toContain('The email settings could not be loaded.')
		expect(wrapper.findAll('button').some((node) => node.text() === 'Save')).toBe(false)
	})
})
