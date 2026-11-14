// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DeliveriesPage, Delivery, EntriesPage, EntryRecord, VersionDefinition } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import EntriesView from './EntriesView.vue'

const listEntriesMock = vi.fn()
const deleteEntryMock = vi.fn()
const listDeliveriesMock = vi.fn()
const retryMock = vi.fn()

// Rejecting vi.fn()s are reported as unhandled by Vitest, so failures go through these switches instead.
let failEntries = false
let failRetry = false
let failDelete = false

vi.mock('../../api/forms.ts', () => ({
	listEntries: (...args: unknown[]) => {
		if (failEntries) {
			return new Promise((_resolve, reject) => setTimeout(() => reject(new Error('x')), 0))
		}
		return listEntriesMock(...args)
	},
	deleteEntry: (...args: unknown[]) => {
		if (failDelete) {
			return new Promise((_resolve, reject) => setTimeout(() => reject(new Error('x')), 0))
		}
		return deleteEntryMock(...args)
	},
	listDeliveries: (...args: unknown[]) => listDeliveriesMock(...args),
	retryDelivery: (...args: unknown[]) => {
		if (failRetry) {
			return new Promise((_resolve, reject) => setTimeout(() => reject(new Error('x')), 0))
		}
		return retryMock(...args)
	},
	exportUrl: (id: number) => '/export/' + id,
}))

const version: VersionDefinition = {
	fields: {
		f_name: { type: 'text', label: 'Name' },
		f_kind: { type: 'select', label: 'Kind', options: [{ value: 'new', label: 'New website' }] },
	},
	pages: [{ id: 'p_1', title: null, rows: [{ id: 'r_1', cells: [{ id: 'c_1', span: 6, field: 'f_name' }, { id: 'c_2', span: 6, field: 'f_kind' }] }] }],
}

function entry(id: number, data: Record<string, unknown> = { f_name: 'Ada', f_kind: 'new' }): EntryRecord {
	return { id, versionId: 1, userId: null, submittedAt: 1794700000 + id, data }
}

function delivery(id: number, overrides: Partial<Delivery> = {}): Delivery {
	return { id, entryId: 2, actionId: 'act_hook', type: 'webhook', target: 'hooks.example.com', status: 'failed', attempt: 1, error: 'secret_missing', createdAt: 1794700000, updatedAt: 1794700001, nextAttemptAt: null, ...overrides }
}

function page(entries: EntryRecord[], total = entries.length): EntriesPage {
	return { total, entries, versions: { 1: version } }
}

function deliveries(rows: Delivery[], total = rows.length): DeliveriesPage {
	return { total, rows }
}

function mountView() {
	return mount(EntriesView, { props: { formId: 7, title: 'Inquiry' }, attachTo: document.body })
}

const buttonByText = (wrapper: ReturnType<typeof mountView>, text: string) => wrapper.findAll('button').find((b) => b.text().includes(text))!

describe('EntriesView', () => {
	beforeEach(() => {
		failEntries = false
		failRetry = false
		failDelete = false
		listEntriesMock.mockReset().mockResolvedValue(page([entry(2), entry(1, { f_name: 'Bo' })]))
		deleteEntryMock.mockReset().mockResolvedValue(undefined)
		retryMock.mockReset()
		listDeliveriesMock.mockReset().mockImplementation(async (_id: number, query: { status?: string, entryId?: number }) => {
			if (query.status === 'failed' && query.entryId === undefined) {
				return deliveries([], 0)
			}
			return deliveries([])
		})
		document.body.innerHTML = ''
	})

	it('lists responses newest first with a readable preview', async () => {
		const wrapper = mountView()
		await flushPromises()
		expect(wrapper.text()).toContain('Inquiry')
		expect(wrapper.text()).toContain('2 responses')
		const cards = wrapper.findAllComponents({ name: 'ResponseCard' })
		expect(cards).toHaveLength(2)
		// The select shows its option label, not the stored value.
		expect(cards[0].text()).toContain('New website')
		expect(cards[1].text()).toContain('Bo')
		wrapper.unmount()
	})

	it('says so when there are no responses, and disables the download', async () => {
		listEntriesMock.mockResolvedValue(page([]))
		const wrapper = mountView()
		await flushPromises()
		expect(wrapper.text()).toContain('No responses yet')
		const download = wrapper.find('a[href="/export/7"], button[disabled]')
		expect(download.exists()).toBe(true)
		wrapper.unmount()
	})

	it('links the download to the export endpoint', async () => {
		const wrapper = mountView()
		await flushPromises()
		expect(wrapper.find('a[href="/export/7"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('opens a response to show every question of its own version, with — for unanswered ones', async () => {
		listEntriesMock.mockResolvedValue(page([entry(1, { f_name: 'Ada' })]))
		const wrapper = mountView()
		await flushPromises()
		await wrapper.find('.af-response__head').trigger('click')
		await flushPromises()
		expect(wrapper.find('dl').text()).toContain('Name')
		expect(wrapper.find('dl').text()).toContain('Ada')
		expect(wrapper.find('dl').text()).toContain('Kind')
		expect(wrapper.find('dl').text()).toContain('—')
		wrapper.unmount()
	})

	it('shows answers as text and never as markup', async () => {
		listEntriesMock.mockResolvedValue(page([entry(1, { f_name: '<img src=x onerror=alert(1)>' })]))
		const wrapper = mountView()
		await flushPromises()
		await wrapper.find('.af-response__head').trigger('click')
		expect(wrapper.find('img').exists()).toBe(false)
		expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>')
		wrapper.unmount()
	})

	it('loads a response\'s deliveries the first time it is opened, and not before', async () => {
		listDeliveriesMock.mockImplementation(async (_id: number, query: { status?: string, entryId?: number }) => {
			return query.entryId === 2 ? deliveries([delivery(10, { entryId: 2 })]) : deliveries([], 0)
		})
		const wrapper = mountView()
		await flushPromises()
		expect(listDeliveriesMock.mock.calls.some(([, q]) => q.entryId !== undefined)).toBe(false)
		await wrapper.findAll('.af-response__head')[0].trigger('click')
		await flushPromises()
		expect(listDeliveriesMock).toHaveBeenCalledWith(7, { entryId: 2, limit: 50 })
		expect(wrapper.text()).toContain('hooks.example.com')
		expect(wrapper.text()).toContain('no signing secret')
		wrapper.unmount()
	})

	it('retries a failed delivery from a response and shows it waiting again', async () => {
		listDeliveriesMock.mockImplementation(async (_id: number, query: { status?: string, entryId?: number }) => {
			return query.entryId === 2 ? deliveries([delivery(10)]) : deliveries([], 0)
		})
		retryMock.mockResolvedValue(delivery(10, { status: 'queued', attempt: 0, error: null, nextAttemptAt: Math.floor(Date.now() / 1000) + 5 }))
		const wrapper = mountView()
		await flushPromises()
		await wrapper.findAll('.af-response__head')[0].trigger('click')
		await flushPromises()
		await buttonByText(wrapper, 'Try again').trigger('click')
		await flushPromises()
		expect(retryMock).toHaveBeenCalledWith(7, 10)
		expect(wrapper.find('.af-delivery').classes()).toContain('af-delivery--queued')
		expect(buttonByText(wrapper, 'Try again')).toBeUndefined()
		wrapper.unmount()
	})

	it('asks before deleting, then removes the response and updates the count', async () => {
		const wrapper = mountView()
		await flushPromises()
		await wrapper.findAll('.af-response__head')[0].trigger('click')
		await flushPromises()
		await buttonByText(wrapper, 'Delete response').trigger('click')
		await flushPromises()
		expect(deleteEntryMock).not.toHaveBeenCalled()
		expect(document.body.textContent).toContain('This cannot be undone')

		const confirm = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Delete')!
		confirm.click()
		await flushPromises()
		expect(deleteEntryMock).toHaveBeenCalledWith(7, 2)
		expect(wrapper.findAllComponents({ name: 'ResponseCard' })).toHaveLength(1)
		expect(wrapper.text()).toContain('1 responses')
		wrapper.unmount()
	})

	it('keeps the response and says so when deleting fails', async () => {
		failDelete = true
		const wrapper = mountView()
		await flushPromises()
		await wrapper.findAll('.af-response__head')[0].trigger('click')
		await buttonByText(wrapper, 'Delete response').trigger('click')
		await flushPromises()
		;[...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Delete')!.click()
		await new Promise((resolve) => setTimeout(resolve, 5))
		await flushPromises()
		expect(wrapper.findAllComponents({ name: 'ResponseCard' })).toHaveLength(2)
		expect(wrapper.text()).toContain('Could not delete the response')
		wrapper.unmount()
	})

	it('pages through responses with Show more', async () => {
		listEntriesMock.mockReset()
			.mockResolvedValueOnce(page([entry(3), entry(2)], 3))
			.mockResolvedValueOnce(page([entry(1)], 3))
		const wrapper = mountView()
		await flushPromises()
		expect(wrapper.text()).toContain('Showing 2 of 3')
		await buttonByText(wrapper, 'Show more').trigger('click')
		await flushPromises()
		expect(listEntriesMock).toHaveBeenLastCalledWith(7, 50, 2)
		expect(wrapper.findAllComponents({ name: 'ResponseCard' })).toHaveLength(3)
		expect(buttonByText(wrapper, 'Show more')).toBeUndefined()
		wrapper.unmount()
	})

	it('offers a retry when the list cannot be loaded', async () => {
		failEntries = true
		const wrapper = mountView()
		await new Promise((resolve) => setTimeout(resolve, 5))
		await flushPromises()
		expect(wrapper.text()).toContain('Could not load the responses')
		failEntries = false
		await buttonByText(wrapper, 'Try again').trigger('click')
		await flushPromises()
		expect(wrapper.findAllComponents({ name: 'ResponseCard' })).toHaveLength(2)
		wrapper.unmount()
	})
})

describe('EntriesView deliveries', () => {
	beforeEach(() => {
		failEntries = false
		failRetry = false
		listEntriesMock.mockReset().mockResolvedValue(page([entry(2), entry(1)]))
		retryMock.mockReset()
		listDeliveriesMock.mockReset().mockImplementation(async (_id: number, query: { status?: string }) => {
			const all = [delivery(10), delivery(9, { status: 'success', error: null, type: 'email', target: 'ada@example.com' }), delivery(8, { status: 'skipped', error: 'no_recipient', type: 'email', target: 'Email' })]
			const rows = query.status ? all.filter((d) => d.status === query.status) : all
			return deliveries(rows)
		})
		document.body.innerHTML = ''
	})

	async function openDeliveries(wrapper: ReturnType<typeof mountView>) {
		await wrapper.findAll('[role="radio"]').find((b) => b.text().startsWith('Deliveries'))!.trigger('click')
		await flushPromises()
	}

	it('flags failed deliveries on the responses tab and in the tab label', async () => {
		listDeliveriesMock.mockImplementation(async (_id: number, query: { status?: string }) => (query.status === 'failed' ? deliveries([delivery(10)], 2) : deliveries([])))
		const wrapper = mountView()
		await flushPromises()
		expect(wrapper.text()).toContain('2 emails or webhooks could not be sent')
		expect(wrapper.text()).toContain('Deliveries (2 failed)')
		wrapper.unmount()
	})

	it('lists every delivery with plain-language reasons', async () => {
		const wrapper = mountView()
		await flushPromises()
		await openDeliveries(wrapper)
		expect(wrapper.findAll('.af-delivery')).toHaveLength(3)
		expect(wrapper.text()).toContain('no signing secret')
		expect(wrapper.text()).toContain('did not give a usable email address')
		expect(wrapper.text()).toContain('ada@example.com')
		wrapper.unmount()
	})

	it('filters by status through the server', async () => {
		const wrapper = mountView()
		await flushPromises()
		await openDeliveries(wrapper)
		await wrapper.findAll('[role="radio"]').find((b) => b.text() === 'Sent')!.trigger('click')
		await flushPromises()
		expect(listDeliveriesMock).toHaveBeenLastCalledWith(7, { status: 'success', limit: 50, offset: 0 })
		expect(wrapper.findAll('.af-delivery')).toHaveLength(1)
		wrapper.unmount()
	})

	it('only offers Try again on failed ones, and calls the retry endpoint', async () => {
		retryMock.mockResolvedValue(delivery(10, { status: 'queued', attempt: 0, error: null, nextAttemptAt: Math.floor(Date.now() / 1000) + 5 }))
		const wrapper = mountView()
		await flushPromises()
		await openDeliveries(wrapper)
		const retries = wrapper.findAll('button').filter((b) => b.text() === 'Try again')
		expect(retries).toHaveLength(1)
		await retries[0].trigger('click')
		await flushPromises()
		expect(retryMock).toHaveBeenCalledWith(7, 10)
		expect(wrapper.findAll('.af-delivery')[0].classes()).toContain('af-delivery--queued')
		wrapper.unmount()
	})

	it('reports a failed retry and keeps the delivery as it was', async () => {
		failRetry = true
		const wrapper = mountView()
		await flushPromises()
		await openDeliveries(wrapper)
		await wrapper.findAll('button').find((b) => b.text() === 'Try again')!.trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 5))
		await flushPromises()
		expect(wrapper.text()).toContain('That did not work')
		expect(wrapper.findAll('.af-delivery')[0].classes()).toContain('af-delivery--failed')
		wrapper.unmount()
	})

	it('jumps from a delivery to its response, opening it', async () => {
		const wrapper = mountView()
		await flushPromises()
		await openDeliveries(wrapper)
		await wrapper.find('.af-delivery__link').trigger('click')
		await flushPromises()
		expect(wrapper.find('.af-response--open').exists()).toBe(true)
		wrapper.unmount()
	})

	it('explains an empty filter', async () => {
		listDeliveriesMock.mockResolvedValue(deliveries([]))
		const wrapper = mountView()
		await flushPromises()
		await openDeliveries(wrapper)
		expect(wrapper.text()).toContain('Nothing sent yet')
		wrapper.unmount()
	})

	describe('refreshing deliveries', () => {
		const callsForList = () => listDeliveriesMock.mock.calls.filter(([, query]) => query.status === undefined).length

		beforeEach(() => vi.useFakeTimers())
		afterEach(() => vi.useRealTimers())

		it('keeps refreshing while something is waiting, and stops once it has settled', async () => {
			let rows = [delivery(10, { status: 'queued', attempt: 1, error: null, nextAttemptAt: Math.floor(Date.now() / 1000) + 30 })]
			listDeliveriesMock.mockImplementation(async (_id: number, query: { status?: string }) => (query.status === 'failed' ? deliveries([]) : deliveries(rows)))
			const wrapper = mountView()
			await flushPromises()
			await openDeliveries(wrapper)
			expect(wrapper.text()).toContain('Waiting')
			expect(wrapper.text()).toContain('Updating automatically')
			const afterOpen = callsForList()

			rows = [delivery(10, { status: 'success', attempt: 2, error: null })]
			await vi.advanceTimersByTimeAsync(10_000)
			await flushPromises()
			expect(callsForList()).toBe(afterOpen + 1)
			expect(wrapper.text()).toContain('Sent')
			expect(wrapper.text()).not.toContain('Updating automatically')

			// Nothing is waiting any more, so nothing more is fetched.
			await vi.advanceTimersByTimeAsync(40_000)
			await flushPromises()
			expect(callsForList()).toBe(afterOpen + 1)
			wrapper.unmount()
		})

		it('does not poll while nothing is waiting, or while the responses tab is showing', async () => {
			listDeliveriesMock.mockImplementation(async (_id: number, query: { status?: string }) => (query.status === 'failed' ? deliveries([]) : deliveries([delivery(10, { status: 'queued', error: null })])))
			const wrapper = mountView()
			await flushPromises()
			const before = listDeliveriesMock.mock.calls.length
			await vi.advanceTimersByTimeAsync(30_000)
			await flushPromises()
			expect(listDeliveriesMock.mock.calls.length).toBe(before)
			wrapper.unmount()
		})

		it('refreshes by hand', async () => {
			const wrapper = mountView()
			await flushPromises()
			await openDeliveries(wrapper)
			const before = callsForList()
			await wrapper.get('button[aria-label="Refresh"]').trigger('click')
			await flushPromises()
			expect(callsForList()).toBe(before + 1)
			wrapper.unmount()
		})
	})
})
