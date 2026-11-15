// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { fetchUserPreferences, saveUserPreferences } from './api/settings.ts'
import PersonalApp from './PersonalApp.vue'

vi.mock('./api/settings.ts', () => ({
	DEFAULT_PREFERENCES: { openPublishedOnResponses: true },
	fetchUserPreferences: vi.fn(),
	saveUserPreferences: vi.fn(),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))

describe('personal settings', () => {
	it('shows the saved preference and saves a change', async () => {
		vi.mocked(fetchUserPreferences).mockResolvedValue({ openPublishedOnResponses: true })
		vi.mocked(saveUserPreferences).mockResolvedValue({ openPublishedOnResponses: false })
		const wrapper = mount(PersonalApp)
		await flushPromises()
		const input = wrapper.find('input[type="checkbox"]')
		expect((input.element as HTMLInputElement).checked).toBe(true)

		await input.setValue(false)
		await flushPromises()
		expect(saveUserPreferences).toHaveBeenCalledWith({ openPublishedOnResponses: false })
		expect((wrapper.find('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(false)
	})

	it('puts the switch back when saving fails', async () => {
		vi.mocked(fetchUserPreferences).mockResolvedValue({ openPublishedOnResponses: true })
		vi.mocked(saveUserPreferences).mockRejectedValue(new Error('offline'))
		const wrapper = mount(PersonalApp)
		await flushPromises()
		await wrapper.find('input[type="checkbox"]').setValue(false)
		await flushPromises()
		expect((wrapper.find('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(true)
	})
})
