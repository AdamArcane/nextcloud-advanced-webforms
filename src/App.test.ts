// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { UserPreferences } from './api/settings.ts'
import type { OwnerForm } from './types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { duplicateForm, listForms } from './api/forms.ts'
import { fetchUserPreferences, saveUserPreferences } from './api/settings.ts'
import { showError } from '@nextcloud/dialogs'
import App from './App.vue'
import { FORM_STATUS_CLOSED, FORM_STATUS_PUBLISHED, FORM_STATUS_UNPUBLISHED } from './types.ts'

vi.mock('./api/forms.ts', () => ({
	listForms: vi.fn(),
	createForm: vi.fn(),
	duplicateForm: vi.fn(),
	importForm: vi.fn(),
	setFormArchived: vi.fn(),
	formExportUrl: () => '#',
	formsBackupUrl: () => '/apps/webforms/api/v1/backup',
}))
vi.mock('./api/settings.ts', () => ({
	OPEN_POLICY: { canCreate: true },
	fetchUserPolicy: () => Promise.resolve({ canCreate: true }),
	DEFAULT_PREFERENCES: { openPublishedOnResponses: true, newFormLabelPosition: 'above', newFormAccentColor: '', newFormHideTitle: false, newFormCaptcha: true, responsesView: 'table', responsesSummary: true },
	fetchUserPreferences: vi.fn(),
	saveUserPreferences: vi.fn(),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))

const ALL: UserPreferences = { openPublishedOnResponses: true, newFormLabelPosition: 'above', newFormAccentColor: '', newFormHideTitle: false, newFormCaptcha: true, responsesView: 'table', responsesSummary: true }

function form(id: number, status: number, hasUnpublishedChanges: boolean, latestVersionId: number | null = 1): OwnerForm {
	return { id, title: 'Form ' + id, description: null, status, publicToken: null, latestVersionId, createdAt: 0, updatedAt: 0, hasUnpublishedChanges }
}

async function mountList(openPublishedOnResponses: boolean) {
	vi.mocked(fetchUserPreferences).mockResolvedValue({ ...ALL, openPublishedOnResponses })
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

		vi.mocked(saveUserPreferences).mockResolvedValue({ ...ALL, openPublishedOnResponses: false })
		await input.setValue(false)
		await flushPromises()
		expect(saveUserPreferences).toHaveBeenCalledWith({ openPublishedOnResponses: false })
		const after = wrapper.findAll('a').map((link) => link.attributes('href')).filter((href) => href?.startsWith('#/f/'))
		expect(after).toEqual(['#/f/1', '#/f/2', '#/f/3', '#/f/4'])
		wrapper.unmount()
	})
})

async function openDialog(wrapper: Awaited<ReturnType<typeof mountList>>['wrapper']) {
	const entry = wrapper.findAll('.app-navigation-entry__settings a, .app-navigation-entry__settings button').find((el) => el.text() === 'Settings')
	await entry?.trigger('click')
	await flushPromises()
}

describe('saving a preference', () => {
	it('saves only what was changed and keeps the answer of the server', async () => {
		const { wrapper } = await mountList(true)
		await openDialog(wrapper)
		vi.mocked(saveUserPreferences).mockReset().mockResolvedValue({ ...ALL, newFormCaptcha: false })
		const row = wrapper.findAll('.checkbox-radio-switch').find((el) => el.text().includes('spam check'))!
		await row.find('input').setValue(false)
		await flushPromises()
		expect(saveUserPreferences).toHaveBeenCalledTimes(1)
		expect(saveUserPreferences).toHaveBeenCalledWith({ newFormCaptcha: false })
		expect((row.find('input').element as HTMLInputElement).checked).toBe(false)
		wrapper.unmount()
	})

	it('puts the setting back and says so when the server refuses', async () => {
		const { wrapper } = await mountList(true)
		await openDialog(wrapper)
		vi.mocked(showError).mockClear()
		vi.mocked(saveUserPreferences).mockReset().mockRejectedValue(new Error('offline'))
		const row = wrapper.findAll('.checkbox-radio-switch').find((el) => el.text().includes('Leave the title off'))!
		await row.find('input').setValue(true)
		await flushPromises()
		expect((row.find('input').element as HTMLInputElement).checked).toBe(false)
		expect(showError).toHaveBeenCalled()
		wrapper.unmount()
	})

	it('shows the responses the way the preference says, and saves a change made there', async () => {
		vi.mocked(fetchUserPreferences).mockResolvedValue({ ...ALL, responsesView: 'cards', responsesSummary: false })
		vi.mocked(listForms).mockResolvedValue([form(1, FORM_STATUS_PUBLISHED, false)])
		window.location.hash = '#/f/1/responses'
		const wrapper = mount(App, { global: { stubs: { BuilderView: true, EntriesView: true, teleport: true } } })
		await flushPromises()
		const view = wrapper.findComponent({ name: 'EntriesView' })
		expect(view.props('responsesView')).toBe('cards')
		expect(view.props('responsesSummary')).toBe(false)

		vi.mocked(saveUserPreferences).mockReset().mockResolvedValue({ ...ALL, responsesView: 'table', responsesSummary: false })
		view.vm.$emit('preference', { responsesView: 'table' })
		await flushPromises()
		expect(saveUserPreferences).toHaveBeenCalledWith({ responsesView: 'table' })
		expect(wrapper.findComponent({ name: 'EntriesView' }).props('responsesView')).toBe('table')
		window.location.hash = ''
		wrapper.unmount()
	})
})

describe('new responses in the forms list', () => {
	it('shows how many arrived since the last look, only on forms that have some', async () => {
		vi.mocked(fetchUserPreferences).mockResolvedValue(ALL)
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
		vi.mocked(fetchUserPreferences).mockResolvedValue(ALL)
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

/** The sidebar rows with their menu items always in the page, since the real menu only renders once it is opened. */
async function mountWithMenus() {
	vi.mocked(fetchUserPreferences).mockResolvedValue(ALL)
	vi.mocked(listForms).mockResolvedValue([form(1, FORM_STATUS_PUBLISHED, false), form(2, FORM_STATUS_UNPUBLISHED, true, null)])
	const wrapper = mount(App, {
		global: {
			stubs: {
				BuilderView: true,
				EntriesView: true,
				teleport: true,
				NcAppNavigationItem: { template: '<li><slot /><slot name="actions" /></li>' },
				NcActionButton: { template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' },
				NcActionLink: { template: '<a><slot /></a>' },
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('duplicating a form', () => {
	it('asks for a copy named after the original, adds it to the list and opens it', async () => {
		const wrapper = await mountWithMenus()
		const copy = { ...form(9, FORM_STATUS_UNPUBLISHED, true, null), title: 'Copy of Form 1' }
		vi.mocked(duplicateForm).mockResolvedValue({ form: copy, errors: [] })
		window.location.hash = ''
		const actions = wrapper.findAll('button').filter((el) => el.text() === 'Duplicate')
		// One per form in the list, the first form first.
		expect(actions).toHaveLength(2)
		await actions[0].trigger('click')
		await flushPromises()
		expect(duplicateForm).toHaveBeenCalledWith(1, 'Copy of Form 1')
		expect(window.location.hash).toBe('#/f/9')
	})

	it('says so when the copy could not be made', async () => {
		const wrapper = await mountWithMenus()
		vi.mocked(duplicateForm).mockRejectedValue(new Error('no'))
		await wrapper.findAll('button').filter((el) => el.text() === 'Duplicate')[0].trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('Could not duplicate the form.')
	})
})
