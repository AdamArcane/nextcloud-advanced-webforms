// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { EntryDetail, EntryRecord, VersionDefinition } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/http.ts'
import ResponsePage from './ResponsePage.vue'

const getEntryMock = vi.fn()
const setStatusMock = vi.fn()
const setNoteMock = vi.fn()
const listDeliveriesMock = vi.fn()
// Rejecting vi.fn()s are reported as unhandled by Vitest, so failures go through this switch instead.
let entryFailure: unknown = null

vi.mock('../../api/forms.ts', () => ({
	getEntry: (...args: unknown[]) => (entryFailure ? new Promise((_resolve, reject) => setTimeout(() => reject(entryFailure), 0)) : getEntryMock(...args)),
	setEntryStatus: (...args: unknown[]) => setStatusMock(...args),
	setEntryNote: (...args: unknown[]) => setNoteMock(...args),
	listDeliveries: (...args: unknown[]) => listDeliveriesMock(...args),
	retryDelivery: vi.fn(),
}))

const version: VersionDefinition = {
	fields: {
		f_name: { type: 'text', label: 'Name' },
		f_kind: { type: 'select', label: 'Kind', options: [{ value: 'new', label: 'New website' }] },
		f_cv: { type: 'file', label: 'CV' },
	},
	pages: [{ id: 'p_1', title: null, rows: [{ id: 'r_1', cells: [{ id: 'c_1', span: 4, field: 'f_name' }, { id: 'c_2', span: 4, field: 'f_kind' }, { id: 'c_3', span: 4, field: 'f_cv' }] }] }],
}
const STATUSES = [{ id: 'done', label: 'Done', color: 'green' }]

function record(id: number, extra: Partial<EntryRecord> = {}): EntryRecord {
	return { id, versionId: 1, userId: 'ada', submittedAt: 1794700000 + id, status: null, note: null, data: { f_name: 'Ada', f_cv: [{ name: 'cv.pdf', size: 2048, type: 'application/pdf' }] }, ...extra }
}

function detail(id: number, extra: Partial<EntryDetail> = {}, entry: Partial<EntryRecord> = {}): EntryDetail {
	return { entry: record(id, entry), version, previousId: id - 1, nextId: id + 1, ...extra }
}

async function mountPage(entryId = 5, statuses = STATUSES) {
	const wrapper = mount(ResponsePage, { props: { formId: 7, entryId, title: 'Inquiry', statuses }, attachTo: document.body })
	await flushPromises()
	return wrapper
}

const link = (wrapper: Awaited<ReturnType<typeof mountPage>>, text: string) => wrapper.findAll('a, button').find((item) => item.text() === text)!

describe('ResponsePage', () => {
	beforeEach(() => {
		entryFailure = null
		getEntryMock.mockReset().mockImplementation(async (_form: number, id: number) => detail(id))
		setStatusMock.mockReset().mockImplementation(async (_form: number, id: number, status: string | null) => record(id, { status }))
		setNoteMock.mockReset().mockImplementation(async (_form: number, id: number, note: string) => record(id, { note }))
		listDeliveriesMock.mockReset().mockResolvedValue({ total: 0, rows: [] })
		document.body.innerHTML = ''
	})

	it('shows every question of the response\'s own version, answered or not, with files as downloads', async () => {
		const wrapper = await mountPage()
		expect(wrapper.find('.af-page__title').text()).toBe('Response #5')
		expect(wrapper.find('.af-page__form').text()).toBe('Inquiry')
		expect(wrapper.find('.af-page__when').text()).toContain('ada')
		const answers = wrapper.find('dl.af-page__answers')
		expect(answers.findAll('dt').map((dt) => dt.text())).toEqual(['Name', 'Kind', 'CV'])
		expect(answers.findAll('dd')[0].text()).toBe('Ada')
		expect(answers.findAll('dd')[1].text()).toBe('Not answered')
		expect(answers.find('a[download]').text()).toBe('cv.pdf')
		expect(listDeliveriesMock).toHaveBeenCalledWith(7, { entryId: 5, limit: 50 })
		expect(wrapper.text()).toContain('Nothing was sent for this response.')
		wrapper.unmount()
	})

	it('steps to the response before and after, and back to the list', async () => {
		const wrapper = await mountPage()
		expect(link(wrapper, 'All responses').attributes('href')).toBe('#/f/7/responses')
		expect(link(wrapper, 'Earlier').attributes('href')).toBe('#/f/7/responses/4')
		expect(link(wrapper, 'Later').attributes('href')).toBe('#/f/7/responses/6')

		await wrapper.setProps({ entryId: 6 })
		await flushPromises()
		expect(getEntryMock).toHaveBeenLastCalledWith(7, 6)
		expect(wrapper.find('.af-page__title').text()).toBe('Response #6')
		wrapper.unmount()
	})

	it('has nowhere to step at either end', async () => {
		getEntryMock.mockResolvedValue(detail(5, { previousId: null, nextId: null }))
		const wrapper = await mountPage()
		expect(link(wrapper, 'Earlier').attributes('disabled')).toBeDefined()
		expect(link(wrapper, 'Earlier').attributes('href')).toBeUndefined()
		expect(link(wrapper, 'Later').attributes('disabled')).toBeDefined()
		wrapper.unmount()
	})

	it('changes the status and tells the list', async () => {
		const wrapper = await mountPage()
		await wrapper.find('select[aria-label="Status of response 5"]').setValue('done')
		await flushPromises()
		expect(setStatusMock).toHaveBeenCalledWith(7, 5, 'done')
		expect((wrapper.find('select[aria-label="Status of response 5"]').element as HTMLSelectElement).value).toBe('done')
		expect(wrapper.emitted('updated')![0]).toEqual([record(5, { status: 'done' })])
		wrapper.unmount()
	})

	it('hides the status when the server has no statuses', async () => {
		const wrapper = await mountPage(5, [])
		expect(wrapper.find('select[aria-label="Status of response 5"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('shows the note and saves a changed one', async () => {
		getEntryMock.mockResolvedValue(detail(5, {}, { note: 'Called on Monday' }))
		const wrapper = await mountPage()
		const note = wrapper.find('#af-note-5')
		expect((note.element as HTMLTextAreaElement).value).toBe('Called on Monday')
		await note.setValue('Called on Tuesday')
		await link(wrapper, 'Save note').trigger('click')
		await flushPromises()
		expect(setNoteMock).toHaveBeenCalledWith(7, 5, 'Called on Tuesday')
		expect(wrapper.emitted('updated')![0]).toEqual([record(5, { note: 'Called on Tuesday' })])
		expect(wrapper.text()).toContain('Saved')
		wrapper.unmount()
	})

	it('keeps the status, the note and the navigation out of a printout', async () => {
		const wrapper = await mountPage()
		expect(wrapper.find('nav').classes()).toContain('af-noprint')
		expect(wrapper.find('aside').classes()).toContain('af-noprint')
		// The answers are not inside anything that is left out.
		expect(wrapper.find('dl.af-page__answers').element.closest('.af-noprint')).toBeNull()
		// The test DOM has no print, so one is put there for the click.
		const print = vi.fn()
		window.print = print
		await link(wrapper, 'Print').trigger('click')
		expect(print).toHaveBeenCalledTimes(1)
		wrapper.unmount()
	})

	it('says so when the response does not exist, or cannot be loaded', async () => {
		entryFailure = new ApiError(404, 'not_found')
		let wrapper = await mountPage()
		await new Promise((resolve) => setTimeout(resolve, 5))
		await flushPromises()
		expect(wrapper.text()).toContain('This response does not exist')
		expect(wrapper.find('dl.af-page__answers').exists()).toBe(false)
		wrapper.unmount()

		entryFailure = new ApiError(500, 'failed')
		wrapper = await mountPage()
		await new Promise((resolve) => setTimeout(resolve, 5))
		await flushPromises()
		expect(wrapper.text()).toContain('Could not load the response.')
		wrapper.unmount()
	})
})
