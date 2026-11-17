// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { showError } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { saveUserPreferences } from '../api/settings.ts'
import SettingsDialog from './SettingsDialog.vue'

vi.mock('../api/settings.ts', () => ({ saveUserPreferences: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))

function mountDialog(openPublishedOnResponses: boolean, loaded = true) {
	return mount(SettingsDialog, {
		props: { open: true, preferences: { openPublishedOnResponses }, loaded },
		global: { stubs: { teleport: true } },
	})
}

const checkbox = (wrapper: ReturnType<typeof mountDialog>) => wrapper.find('input[type="checkbox"]')

beforeEach(() => {
	vi.mocked(saveUserPreferences).mockReset()
	vi.mocked(showError).mockReset()
})

describe('settings dialog', () => {
	it('shows the saved preference, saves a change and tells the parent what the server kept', async () => {
		vi.mocked(saveUserPreferences).mockResolvedValue({ openPublishedOnResponses: false })
		const wrapper = mountDialog(true)
		expect((checkbox(wrapper).element as HTMLInputElement).checked).toBe(true)

		await checkbox(wrapper).setValue(false)
		await flushPromises()
		expect(saveUserPreferences).toHaveBeenCalledWith({ openPublishedOnResponses: false })
		expect((checkbox(wrapper).element as HTMLInputElement).checked).toBe(false)
		expect(wrapper.emitted('saved')).toEqual([[{ openPublishedOnResponses: false }]])
	})

	it('puts the switch back and says so when saving fails', async () => {
		vi.mocked(saveUserPreferences).mockRejectedValue(new Error('offline'))
		const wrapper = mountDialog(true)
		await checkbox(wrapper).setValue(false)
		await flushPromises()
		expect((checkbox(wrapper).element as HTMLInputElement).checked).toBe(true)
		expect(showError).toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	it('offers a download of every form as a link', () => {
		const link = mountDialog(true).find('a[href$="/api/v1/backup"]')
		expect(link.exists()).toBe(true)
		expect(link.attributes('download')).toBeDefined()
		expect(link.text()).toContain('Download backup of all my forms')
	})

	it('keeps the switch off until the preferences have been read', () => {
		expect(checkbox(mountDialog(true, false)).attributes('disabled')).toBeDefined()
	})

	it('follows the parent when the value changes elsewhere', async () => {
		const wrapper = mountDialog(true)
		await wrapper.setProps({ preferences: { openPublishedOnResponses: false } })
		expect((checkbox(wrapper).element as HTMLInputElement).checked).toBe(false)
	})
})
