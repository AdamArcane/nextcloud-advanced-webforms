// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OwnerForm } from './types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { listForms } from './api/forms.ts'
import { fetchUserPreferences, saveUserPreferences } from './api/settings.ts'
import App from './App.vue'
import { FORM_STATUS_CLOSED, FORM_STATUS_PUBLISHED, FORM_STATUS_UNPUBLISHED } from './types.ts'

vi.mock('./api/forms.ts', () => ({
	listForms: vi.fn(),
	createForm: vi.fn(),
	importForm: vi.fn(),
	setFormArchived: vi.fn(),
	formExportUrl: () => '#',
}))
vi.mock('./api/settings.ts', () => ({
	OPEN_POLICY: { canCreate: true },
	fetchUserPolicy: () => Promise.resolve({ canCreate: true }),
	DEFAULT_PREFERENCES: { openPublishedOnResponses: true },
	fetchUserPreferences: vi.fn(),
	saveUserPreferences: vi.fn(),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))

function form(id: number, status: number, hasUnpublishedChanges: boolean, latestVersionId: number | null = 1): OwnerForm {
	return { id, title: 'Form ' + id, description: null, status, publicToken: null, latestVersionId, createdAt: 0, updatedAt: 0, hasUnpublishedChanges }
}

async function mountList(openPublishedOnResponses: boolean) {
	vi.mocked(fetchUserPreferences).mockResolvedValue({ openPublishedOnResponses })
	vi.mocked(listForms).mockResolvedValue([
		form(1, FORM_STATUS_PUBLISHED, false),
		form(2, FORM_STATUS_PUBLISHED, true),
		form(3, FORM_STATUS_UNPUBLISHED, true, null),
		form(4, FORM_STATUS_CLOSED, false),
	])
	const wrapper = mount(App, { global: { stubs: { BuilderView: true, EntriesView: true, teleport: true } } })
	await flushPromises()
	const hrefs = wrapper.findAll('a').map((link) => link.attributes('href')).filter((href) => href?.startsWith('#/f/'))
	return { wrapper, hrefs }
}

describe('where a form in the list opens', () => {
	it('opens every form in the editor when the preference is off', async () => {
		const { hrefs } = await mountList(false)
		expect(hrefs).toEqual(['#/f/1', '#/f/2', '#/f/3', '#/f/4'])
	})

	it('opens a published form with no unpublished changes on its responses, and any other form in the editor', async () => {
		const { wrapper, hrefs } = await mountList(true)
		expect(hrefs).toEqual(['#/f/1/responses', '#/f/2', '#/f/3', '#/f/4'])

		await wrapper.find('a[href="#/f/1/responses"]').trigger('click')
		expect(window.location.hash).toBe('#/f/1/responses')
	})
})

describe('settings in the sidebar', () => {
	it('opens from the bottom of the sidebar, and a change moves where the forms open at once', async () => {
		const { wrapper, hrefs } = await mountList(true)
		expect(hrefs[0]).toBe('#/f/1/responses')
		expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)

		const entry = wrapper.findAll('.app-navigation-entry__settings a, .app-navigation-entry__settings button').find((el) => el.text() === 'Settings')
		expect(entry).toBeDefined()
		await entry?.trigger('click')
		await flushPromises()
		const input = wrapper.find('input[type="checkbox"]')
		expect((input.element as HTMLInputElement).checked).toBe(true)

		vi.mocked(saveUserPreferences).mockResolvedValue({ openPublishedOnResponses: false })
		await input.setValue(false)
		await flushPromises()
		expect(saveUserPreferences).toHaveBeenCalledWith({ openPublishedOnResponses: false })
		const after = wrapper.findAll('a').map((link) => link.attributes('href')).filter((href) => href?.startsWith('#/f/'))
		expect(after).toEqual(['#/f/1', '#/f/2', '#/f/3', '#/f/4'])
		wrapper.unmount()
	})
})

describe('new responses in the forms list', () => {
	it('shows how many arrived since the last look, only on forms that have some', async () => {
		vi.mocked(fetchUserPreferences).mockResolvedValue({ openPublishedOnResponses: true })
		vi.mocked(listForms).mockResolvedValue([
			{ ...form(1, FORM_STATUS_PUBLISHED, false), newCount: 3 },
			{ ...form(2, FORM_STATUS_PUBLISHED, false), newCount: 0 },
			form(3, FORM_STATUS_PUBLISHED, false),
		])
		const wrapper = mount(App, { global: { stubs: { BuilderView: true, EntriesView: true, teleport: true } } })
		await flushPromises()
		const bubbles = wrapper.findAll('[aria-label$="new responses"]')
		expect(bubbles).toHaveLength(1)
		expect(bubbles[0].text()).toBe('3')
		wrapper.unmount()
	})

	it('reads one response from the address, and passes it to the responses view', async () => {
		vi.mocked(fetchUserPreferences).mockResolvedValue({ openPublishedOnResponses: true })
		vi.mocked(listForms).mockResolvedValue([{ ...form(1, FORM_STATUS_PUBLISHED, false), newCount: 2 }])
		window.location.hash = '#/f/1/responses/42'
		const wrapper = mount(App, { global: { stubs: { BuilderView: true, EntriesView: true, teleport: true } } })
		await flushPromises()
		const view = wrapper.findComponent({ name: 'EntriesView' })
		expect(view.props('entryId')).toBe(42)
		expect(view.props('formId')).toBe(1)

		// Looking at the list clears the count beside the form.
		view.vm.$emit('seen')
		await flushPromises()
		expect(wrapper.findAll('[aria-label$="new responses"]')).toHaveLength(0)
		window.location.hash = ''
		wrapper.unmount()
	})
})
