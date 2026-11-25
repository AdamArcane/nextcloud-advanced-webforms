// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { UserPreferences } from './api/settings.ts'
import type { OwnerForm } from './types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createFolder, deleteFolder, listFolders, setFormFolder } from './api/folders.ts'
import { duplicateForm, leaveForm, listForms } from './api/forms.ts'
import { fetchUserPreferences, saveUserPreferences } from './api/settings.ts'
import { showError } from '@nextcloud/dialogs'
import App from './App.vue'
import { FORM_STATUS_CLOSED, FORM_STATUS_PUBLISHED, FORM_STATUS_UNPUBLISHED } from './types.ts'

// Which sections are open is kept in the browser, so each test starts with nothing remembered.
beforeEach(() => {
	window.localStorage.clear()
})

vi.mock('./api/forms.ts', () => ({
	listForms: vi.fn(),
	createForm: vi.fn(),
	duplicateForm: vi.fn(),
	leaveForm: vi.fn(),
	importForm: vi.fn(),
	fetchCoreForms: vi.fn(() => Promise.resolve({ available: false, forms: [] })),
	importCoreForm: vi.fn(),
	setFormArchived: vi.fn(),
	formExportUrl: () => '#',
	formsBackupUrl: () => '/apps/webforms/api/v1/backup',
}))
vi.mock('./api/folders.ts', () => ({
	listFolders: vi.fn(() => Promise.resolve([])),
	createFolder: vi.fn(),
	renameFolder: vi.fn(),
	deleteFolder: vi.fn(),
	setFormFolder: vi.fn(),
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
	const hrefs = wrapper.findAll('a').filter((link) => !link.element.closest('.af-overview')).map((link) => link.attributes('href')).filter((href) => href?.startsWith('#/f/'))
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
		const after = wrapper.findAll('a').filter((link) => !link.element.closest('.af-overview')).map((link) => link.attributes('href')).filter((href) => href?.startsWith('#/f/'))
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

function shared(id: number, role: 'editor' | 'reviewer', extra: Partial<OwnerForm> = {}): OwnerForm {
	return { ...form(id, FORM_STATUS_PUBLISHED, false), title: 'Shared ' + id, role, ownerId: 'olga', ownerName: 'Olga Owner', canLeave: true, ...extra }
}

const TEAM = () => [
	{ ...form(1, FORM_STATUS_PUBLISHED, false), role: 'owner' as const },
	shared(2, 'editor'),
	shared(3, 'reviewer'),
]

async function mountTeam(stubs: Record<string, unknown> = {}, preferences: Partial<UserPreferences> = {}) {
	vi.mocked(fetchUserPreferences).mockResolvedValue({ ...ALL, ...preferences })
	vi.mocked(listForms).mockResolvedValue(TEAM())
	const wrapper = mount(App, { global: { stubs: { BuilderView: true, EntriesView: true, teleport: true, ...stubs } } })
	await flushPromises()
	return wrapper
}

describe('forms shared with the person (docs/SCHEMA.md section 22.3)', () => {
	it('lists them apart under Shared with me, and the person\'s own forms first', async () => {
		const wrapper = await mountTeam()
		const text = wrapper.text()
		expect(text).toContain('Shared with me')
		expect(text.indexOf('Form 1')).toBeLessThan(text.indexOf('Shared with me'))
		expect(text.indexOf('Shared with me')).toBeLessThan(text.indexOf('Shared 2'))
		expect(text.indexOf('Shared 2')).toBeGreaterThan(-1)
		expect(text.indexOf('Shared 3')).toBeGreaterThan(-1)
	})

	it('puts a My forms heading above the person\'s own forms, with an icon on each heading and none on the forms', async () => {
		const wrapper = await mountTeam()
		const text = wrapper.text()
		expect(text.indexOf('My forms')).toBeLessThan(text.indexOf('Form 1'))
		expect(text.indexOf('My forms')).toBeLessThan(text.indexOf('Shared with me'))
		const rows = wrapper.findAll('.app-navigation-entry')
		const heading = (name: string) => rows.find((row) => row.find('.app-navigation-entry-link').text().startsWith(name))!
		expect(heading('My forms').find('.app-navigation-entry-icon svg').exists()).toBe(true)
		expect(heading('Shared with me').find('.app-navigation-entry-icon svg').exists()).toBe(true)
		expect(heading('Form 1').find('.app-navigation-entry-icon svg').exists()).toBe(false)
	})

	it('opens and closes a section by clicking its heading, not only the caret', async () => {
		const wrapper = await mountTeam()
		const link = () => wrapper.findAll('.app-navigation-entry-link').find((one) => one.text().startsWith('Shared with me'))!
		expect(link().attributes('aria-expanded')).toBe('true')
		await link().trigger('click')
		await flushPromises()
		expect(link().attributes('aria-expanded')).toBe('false')
		await link().trigger('click')
		await flushPromises()
		expect(link().attributes('aria-expanded')).toBe('true')
		const mine = wrapper.findAll('.app-navigation-entry-link').find((one) => one.text().startsWith('My forms'))!
		await mine.trigger('click')
		await flushPromises()
		expect(mine.attributes('aria-expanded')).toBe('false')
		expect(link().attributes('aria-expanded')).toBe('true')
	})

	it('remembers which sections were closed when the page is opened again', async () => {
		const first = await mountTeam()
		await first.findAll('.app-navigation-entry-link').find((one) => one.text().startsWith('Shared with me'))!.trigger('click')
		await flushPromises()
		first.unmount()
		const second = await mountTeam()
		const state = (name: string) => second.findAll('.app-navigation-entry-link').find((one) => one.text().startsWith(name))!.attributes('aria-expanded')
		expect(state('Shared with me')).toBe('false')
		expect(state('My forms')).toBe('true')
	})

	it('opens and closes Archived the same way', async () => {
		vi.mocked(fetchUserPreferences).mockResolvedValue(ALL)
		vi.mocked(listForms).mockResolvedValue([{ ...form(1, FORM_STATUS_PUBLISHED, false), role: 'owner' }, { ...form(2, FORM_STATUS_PUBLISHED, false), title: 'Old one', archivedAt: 5 }])
		const wrapper = mount(App, { global: { stubs: { BuilderView: true, EntriesView: true, teleport: true } } })
		await flushPromises()
		const link = () => wrapper.findAll('.app-navigation-entry-link').find((one) => one.text().startsWith('Archived'))!
		expect(link().attributes('aria-expanded')).toBe('false')
		await link().trigger('click')
		await flushPromises()
		expect(link().attributes('aria-expanded')).toBe('true')
	})

	it('has no Shared with me heading when nothing is shared', async () => {
		vi.mocked(fetchUserPreferences).mockResolvedValue(ALL)
		vi.mocked(listForms).mockResolvedValue([{ ...form(1, FORM_STATUS_PUBLISHED, false), role: 'owner' }])
		const wrapper = mount(App, { global: { stubs: { BuilderView: true, EntriesView: true, teleport: true } } })
		await flushPromises()
		expect(wrapper.text()).not.toContain('Shared with me')
	})

	it('opens a form shared for reading on its responses, whatever the preference says', async () => {
		const wrapper = await mountTeam({}, { openPublishedOnResponses: false })
		const hrefs = wrapper.findAll('a').filter((link) => !link.element.closest('.af-overview')).map((link) => link.attributes('href')).filter((href) => href?.startsWith('#/f/'))
		expect(hrefs).toContain('#/f/3/responses')
		// An editor still gets the editor under that preference.
		expect(hrefs).toContain('#/f/2')
	})

	it('shows a Reviewer the responses even at the address of the editor, with their role', async () => {
		window.location.hash = '#/f/3'
		const wrapper = await mountTeam()
		const entries = wrapper.findComponent({ name: 'EntriesView' })
		expect(entries.exists()).toBe(true)
		expect(entries.props('role')).toBe('reviewer')
		expect(wrapper.findComponent({ name: 'BuilderView' }).exists()).toBe(false)
		window.location.hash = ''
	})

	it('shows an editor the editor at that address', async () => {
		window.location.hash = '#/f/2'
		const wrapper = await mountTeam()
		expect(wrapper.findComponent({ name: 'BuilderView' }).exists()).toBe(true)
		window.location.hash = ''
	})

	it('waits for the list before choosing between the editor and the responses', async () => {
		window.location.hash = '#/f/3'
		vi.mocked(fetchUserPreferences).mockResolvedValue(ALL)
		vi.mocked(listForms).mockReturnValue(new Promise(() => {}))
		const wrapper = mount(App, { global: { stubs: { BuilderView: true, EntriesView: true, teleport: true } } })
		await flushPromises()
		expect(wrapper.findComponent({ name: 'BuilderView' }).exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'EntriesView' }).exists()).toBe(false)
		window.location.hash = ''
	})
})

describe('the menu of a form shared with the person', () => {
	const stubs = {
		NcAppNavigationItem: { props: ['name'], template: '<li><span>{{ name }}</span><slot /><slot name="actions" /></li>' },
		NcActionButton: { template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' },
		NcActionLink: { template: '<a><slot /></a>' },
	}

	it('offers a Reviewer nothing but filing it and leaving, and an editor the usual menu without the owner\'s own tools', async () => {
		const wrapper = await mountTeam(stubs)
		const rows = wrapper.findAll('li').filter((row) => row.text().includes('Shared'))
		const labels = (row: (typeof rows)[number]) => row.findAll('button, a').map((el) => el.text())
		const reviewer = rows.find((row) => row.text().startsWith('Shared 3'))!
		expect(labels(reviewer)).toEqual(['Move to folder', 'Leave this form'])
		const editor = rows.find((row) => row.text().startsWith('Shared 2'))!
		expect(labels(editor)).toEqual(['Move to folder', 'Export draft', 'Export published version', 'Duplicate', 'Save as template', 'Archive', 'Leave this form'])
	})

	it('does not offer to leave a form that comes through a group', async () => {
		vi.mocked(fetchUserPreferences).mockResolvedValue(ALL)
		vi.mocked(listForms).mockResolvedValue([shared(2, 'editor', { canLeave: false })])
		const wrapper = mount(App, { global: { stubs: { BuilderView: true, EntriesView: true, teleport: true, ...stubs } } })
		await flushPromises()
		expect(wrapper.findAll('button').some((button) => button.text() === 'Leave this form')).toBe(false)
	})

	it('asks first, then leaves and takes the form out of the list', async () => {
		vi.mocked(leaveForm).mockResolvedValue(undefined)
		const wrapper = await mountTeam(stubs)
		await wrapper.findAll('button').find((button) => button.text() === 'Leave this form')!.trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('Leave this form?')
		expect(leaveForm).not.toHaveBeenCalled()
		await wrapper.findAll('button').find((button) => button.text() === 'Leave form')!.trigger('click')
		await flushPromises()
		expect(leaveForm).toHaveBeenCalledTimes(1)
		expect(wrapper.text()).not.toContain('Shared 2')
		wrapper.unmount()
	})

	it('says so when leaving fails and keeps the form in the list', async () => {
		vi.mocked(leaveForm).mockRejectedValue(new Error('no'))
		const wrapper = await mountTeam(stubs)
		await wrapper.findAll('button').find((button) => button.text() === 'Leave this form')!.trigger('click')
		await flushPromises()
		await wrapper.findAll('button').find((button) => button.text() === 'Leave form')!.trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('Could not leave the form.')
		expect(wrapper.text()).toContain('Shared 2')
		wrapper.unmount()
	})
})

describe('folders in the sidebar', () => {
	beforeEach(() => {
		window.location.hash = ''
		vi.mocked(listFolders).mockReset().mockResolvedValue([{ id: 7, name: 'Events' }])
		vi.mocked(setFormFolder).mockReset().mockResolvedValue()
		vi.mocked(createFolder).mockReset()
		vi.mocked(deleteFolder).mockReset().mockResolvedValue()
		vi.mocked(showError).mockClear()
	})

	// The row menus are popups; stubbed, their buttons are simply there.
	const menus = {
		NcAppNavigationItem: { props: ['name'], template: '<li><span>{{ name }}</span><slot name="actions" /><ul><slot /></ul></li>' },
		NcActionButton: { template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' },
		NcActionLink: { template: '<a><slot /></a>' },
	}
	/** The innermost row that reads `name`: a form or a folder with its menu. */
	const row = (wrapper: Mounted, name: string) => wrapper.findAll('li').find((li) => li.find(':scope > span').exists() && li.find(':scope > span').text() === name)!
	const press = async (from: ReturnType<typeof row>, label: string) => {
		await from.findAll(':scope > button').find((button) => button.text() === label)!.trigger('click')
		await flushPromises()
	}

	async function mountFolders(stubs: Record<string, unknown> = {}) {
		vi.mocked(fetchUserPreferences).mockResolvedValue(ALL)
		vi.mocked(listForms).mockResolvedValue([
			{ ...form(1, FORM_STATUS_UNPUBLISHED, true, null), folderId: 7 },
			{ ...form(2, FORM_STATUS_UNPUBLISHED, true, null), folderId: null },
		])
		const wrapper = mount(App, { global: { stubs: { BuilderView: true, EntriesView: true, teleport: true, ...stubs } } })
		await flushPromises()
		return wrapper
	}

	type Mounted = Awaited<ReturnType<typeof mountFolders>>
	/** The section whose heading reads `name`, as its list item. */
	const section = (wrapper: Mounted, name: string) => wrapper.findAll('li').find((li) => li.find(':scope > div .app-navigation-entry__name').exists() && li.find(':scope > div .app-navigation-entry__name').text() === name)!
	const formsIn = (wrapper: Mounted, name: string) => section(wrapper, name).findAll('a').map((link) => link.attributes('href')).filter((href) => href?.startsWith('#/f/'))
	const byText = (wrapper: Mounted, text: string) => wrapper.findAll('button, a').find((el) => el.text() === text)!

	function dragData() {
		const data = new Map<string, string>()
		return { setData: (type: string, value: string) => data.set(type, value), get types() { return [...data.keys()] } }
	}

	async function drag(wrapper: Mounted, formId: number, onto: string) {
		const dataTransfer = dragData()
		await wrapper.find('a[href="#/f/' + formId + '"]').trigger('dragstart', { dataTransfer })
		await section(wrapper, onto).trigger('dragover', { dataTransfer })
		await section(wrapper, onto).trigger('drop', { dataTransfer })
		await flushPromises()
	}

	it('lists a filed form in its folder and not under My forms', async () => {
		const wrapper = await mountFolders()
		expect(formsIn(wrapper, 'Events')).toEqual(['#/f/1'])
		expect(formsIn(wrapper, 'My forms')).toEqual(['#/f/2'])
		wrapper.unmount()
	})

	it('moves a form dragged onto a folder, and takes it out when dragged onto My forms', async () => {
		const wrapper = await mountFolders()
		await drag(wrapper, 2, 'Events')
		expect(setFormFolder).toHaveBeenCalledWith(2, 7)
		expect(formsIn(wrapper, 'Events')).toEqual(['#/f/1', '#/f/2'])

		await drag(wrapper, 1, 'My forms')
		expect(setFormFolder).toHaveBeenLastCalledWith(1, null)
		expect(formsIn(wrapper, 'My forms')).toEqual(['#/f/1'])
		wrapper.unmount()
	})

	it('does nothing when a form is dropped where it already is, or something else is dropped', async () => {
		const wrapper = await mountFolders()
		await drag(wrapper, 1, 'Events')
		await section(wrapper, 'Events').trigger('drop', { dataTransfer: { types: ['Files'] } })
		expect(setFormFolder).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it('puts the form back and says so when the server refuses', async () => {
		vi.mocked(setFormFolder).mockRejectedValue(new Error('offline'))
		const wrapper = await mountFolders()
		await drag(wrapper, 2, 'Events')
		expect(formsIn(wrapper, 'My forms')).toEqual(['#/f/2'])
		expect(showError).toHaveBeenCalled()
		wrapper.unmount()
	})

	it('shows every form under My forms when the folders cannot be loaded', async () => {
		vi.mocked(listFolders).mockRejectedValue(new Error('offline'))
		const wrapper = await mountFolders()
		expect(formsIn(wrapper, 'My forms')).toEqual(['#/f/1', '#/f/2'])
		expect(wrapper.text()).toContain('Could not load your folders.')
		wrapper.unmount()
	})

	it('makes a folder from the bottom of the sidebar', async () => {
		vi.mocked(createFolder).mockResolvedValue({ id: 8, name: 'Surveys' })
		const wrapper = await mountFolders()
		await byText(wrapper, 'New folder').trigger('click')
		await flushPromises()
		await wrapper.find('input.input-field__input').setValue('Surveys')
		await byText(wrapper, 'Create folder').trigger('click')
		await flushPromises()
		expect(createFolder).toHaveBeenCalledWith('Surveys')
		expect(formsIn(wrapper, 'Surveys')).toEqual([])
		wrapper.unmount()
	})

	it('keeps the forms of a deleted folder, after asking', async () => {
		const wrapper = await mountFolders(menus)
		await press(row(wrapper, 'Events'), 'Delete folder')
		expect(wrapper.text()).toContain('Delete this folder?')
		expect(deleteFolder).not.toHaveBeenCalled()
		await wrapper.findAll('button').filter((button) => button.text() === 'Delete folder').at(-1)!.trigger('click')
		await flushPromises()
		expect(deleteFolder).toHaveBeenCalledWith(7)
		expect(row(wrapper, 'My forms').findAll(':scope > ul > li')).toHaveLength(2)
		wrapper.unmount()
	})

	it('moves a form from its menu', async () => {
		const wrapper = await mountFolders(menus)
		await press(row(wrapper, 'Form 2'), 'Move to folder')
		await wrapper.findAll('.af-move button').find((button) => button.text() === 'Events')!.trigger('click')
		await flushPromises()
		expect(setFormFolder).toHaveBeenCalledWith(2, 7)
		expect(wrapper.find('.af-move').exists()).toBe(false)
		wrapper.unmount()
	})

	it('makes a new folder while moving a form, and puts the form in it', async () => {
		vi.mocked(createFolder).mockResolvedValue({ id: 8, name: 'Surveys' })
		const wrapper = await mountFolders(menus)
		await press(row(wrapper, 'Form 2'), 'Move to folder')
		await wrapper.findAll('button').filter((button) => button.text() === 'New folder').at(-1)!.trigger('click')
		await flushPromises()
		await wrapper.find('input.input-field__input').setValue('Surveys')
		await byText(wrapper, 'Create folder').trigger('click')
		await flushPromises()
		expect(setFormFolder).toHaveBeenCalledWith(2, 8)
		expect(row(wrapper, 'Surveys').findAll(':scope > ul > li')).toHaveLength(1)
		wrapper.unmount()
	})

	it('copies a filed form into the same folder', async () => {
		vi.mocked(duplicateForm).mockResolvedValue({ form: form(9, FORM_STATUS_UNPUBLISHED, true, null), errors: [] })
		const wrapper = await mountFolders(menus)
		await press(row(wrapper, 'Form 1'), 'Duplicate')
		expect(setFormFolder).toHaveBeenCalledWith(9, 7)
		wrapper.unmount()
	})
})
