// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { EntriesPage, EntryRecord, VersionDefinition } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import EntriesView from './EntriesView.vue'

const listEntries = vi.hoisted(() => vi.fn())
const listDeliveries = vi.hoisted(() => vi.fn())

vi.mock('../../api/forms.ts', () => ({
	listEntries: (...args: unknown[]) => listEntries(...args),
	listDeliveries: (...args: unknown[]) => listDeliveries(...args),
	retryDelivery: vi.fn(),
	deleteEntry: vi.fn(),
	bulkDeleteEntries: vi.fn(),
	bulkSetEntryStatus: vi.fn(),
	setEntryStatus: vi.fn(),
	setEntryNote: vi.fn(),
	getEntry: vi.fn(),
	markEntriesSeen: vi.fn().mockResolvedValue(null),
	fetchEntrySummary: vi.fn().mockResolvedValue({ total: 0, truncated: false, timeline: { unit: 'day', buckets: [] }, fields: [] }),
	exportUrl: (id: number) => '/export/' + id,
}))

const version: VersionDefinition = {
	fields: { f_name: { type: 'text', label: 'Name' } },
	pages: [{ id: 'p_1', title: null, rows: [{ id: 'r_1', cells: [{ id: 'c_1', span: 12, field: 'f_name' }] }] }],
}

function entry(id: number): EntryRecord {
	return { id, versionId: 1, userId: null, submittedAt: 1794700000 + id, data: { f_name: 'Ada' } }
}

function mountView(role: 'owner' | 'editor' | 'reviewer', responsesView: 'table' | 'cards') {
	return mount(EntriesView, { props: { formId: 7, title: 'Inquiry', responsesView, role }, attachTo: document.body })
}

beforeEach(() => {
	const page: EntriesPage = { total: 2, grandTotal: 2, truncated: false, entries: [entry(2), entry(1)], versions: { 1: version } }
	listEntries.mockReset().mockResolvedValue(page)
	listDeliveries.mockReset().mockResolvedValue({ total: 0, rows: [] })
	document.body.innerHTML = ''
})

afterEach(() => {
	document.body.innerHTML = ''
})

describe('the responses a Reviewer may read (docs/SCHEMA.md section 22.1)', () => {
	it('has no Deliveries tab and never asks for the delivery log', async () => {
		const wrapper = mountView('reviewer', 'cards')
		await flushPromises()
		expect(wrapper.findAll('[role="radio"]').some((button) => button.text().startsWith('Deliveries'))).toBe(false)
		await wrapper.find('.af-response__head').trigger('click')
		await flushPromises()
		expect(listDeliveries).not.toHaveBeenCalled()
		expect(wrapper.text()).not.toContain('Emails and webhooks')
		wrapper.unmount()
	})

	it('cannot delete a response', async () => {
		const wrapper = mountView('reviewer', 'cards')
		await flushPromises()
		await wrapper.find('.af-response__head').trigger('click')
		await flushPromises()
		expect(wrapper.findAll('button').some((button) => button.text().includes('Delete response'))).toBe(false)
		wrapper.unmount()
	})

	it('can select responses to give them a status, but is not offered Delete', async () => {
		const wrapper = mountView('reviewer', 'table')
		await flushPromises()
		await wrapper.findAll('tbody input[type="checkbox"]')[0].setValue(true)
		const bulk = wrapper.find('.af-entries__bulk')
		expect(bulk.exists()).toBe(true)
		expect(bulk.findAll('button').some((button) => button.text() === 'Delete')).toBe(false)
		wrapper.unmount()
	})
})

describe('the same screen for people who may manage responses', () => {
	it.each(['owner', 'editor'] as const)('%s sees the Deliveries tab, the delivery log and Delete', async (role) => {
		const wrapper = mountView(role, 'cards')
		await flushPromises()
		expect(wrapper.findAll('[role="radio"]').some((button) => button.text().startsWith('Deliveries'))).toBe(true)
		await wrapper.find('.af-response__head').trigger('click')
		await flushPromises()
		expect(listDeliveries).toHaveBeenCalled()
		expect(wrapper.findAll('button').some((button) => button.text().includes('Delete response'))).toBe(true)
		wrapper.unmount()
	})

	it('treats a screen with no role as the owner', async () => {
		const wrapper = mount(EntriesView, { props: { formId: 7, title: 'Inquiry', responsesView: 'table' }, attachTo: document.body })
		await flushPromises()
		await wrapper.findAll('tbody input[type="checkbox"]')[0].setValue(true)
		expect(wrapper.find('.af-entries__bulk').findAll('button').some((button) => button.text() === 'Delete')).toBe(true)
		wrapper.unmount()
	})
})
