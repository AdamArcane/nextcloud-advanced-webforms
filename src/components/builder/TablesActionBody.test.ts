// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DraftDefinition, TablesAction } from '../../types.ts'
import type { TablesColumn, TablesList } from '../../builder/tablesMap.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { addField, emptyDefinition } from '../../builder/definition.ts'
import TablesActionBody from './TablesActionBody.vue'

let tables: TablesList = { available: true, tables: [{ id: 3, title: 'Leads', emoji: '📋' }, { id: 4, title: 'Other', emoji: '' }] }
let columns: TablesColumn[] = []
let tablesFail = false
const columnCalls: number[] = []

vi.mock('../../api/tables.ts', () => ({
	fetchTables: () => (tablesFail ? Promise.reject(new Error('network')) : Promise.resolve(tables)),
	fetchTableColumns: (id: number) => {
		columnCalls.push(id)
		return Promise.resolve(columns)
	},
}))

function column(id: number, title: string, kind: TablesColumn['kind'], extra: Partial<TablesColumn> = {}): TablesColumn {
	return { id, title, type: 'text', subtype: '', mandatory: false, options: [], kind, supported: kind !== null, ...extra }
}

function form(): { def: DraftDefinition, name: string, size: string } {
	let def = emptyDefinition('T')
	const name = addField(def, 'text', { kind: 'row', index: 0 })
	def = { ...name.definition, fields: { ...name.definition.fields, [name.fieldId]: { ...name.definition.fields[name.fieldId], label: 'Full name' } } }
	const size = addField(def, 'number', { kind: 'row', index: 1 })
	def = { ...size.definition, fields: { ...size.definition.fields, [size.fieldId]: { ...size.definition.fields[size.fieldId], label: 'Size' } } }
	return { def, name: name.fieldId, size: size.fieldId }
}

function mountBody(action: Partial<TablesAction>, definition: DraftDefinition) {
	return mount(TablesActionBody, {
		props: { action: { id: 'act_tbl', type: 'tables', tableId: 0, map: {}, ...action }, definition },
	})
}

describe('TablesActionBody', () => {
	beforeEach(() => {
		tables = { available: true, tables: [{ id: 3, title: 'Leads', emoji: '📋' }, { id: 4, title: 'Other', emoji: '' }] }
		columns = [
			column(11, 'Full name', 'text', { mandatory: true }),
			column(12, 'Size', 'number'),
			column(13, 'Assigned to', null),
		]
		tablesFail = false
		columnCalls.length = 0
	})

	it('asks for a table and loads no columns until one is set', async () => {
		const { def } = form()
		const wrapper = mountBody({}, def)
		await flushPromises()
		expect(wrapper.text()).toContain('Table')
		expect(wrapper.text()).not.toContain('Fill these columns')
		expect(columnCalls).toEqual([])
	})

	it('lists the columns of the chosen table, marks required ones, and explains the ones it cannot fill', async () => {
		const { def } = form()
		const wrapper = mountBody({ tableId: 3 }, def)
		await flushPromises()
		expect(columnCalls).toEqual([3])
		const text = wrapper.text()
		expect(text).toContain('Fill these columns')
		expect(text).toContain('Full name *')
		expect(text).toContain('Size')
		// The people column is not offered, only mentioned.
		expect(text).toContain('Not available: Assigned to')
		expect(text).toContain('Choose an answer for at least one column')
	})

	it('"Match by name" maps columns to fields with the same name and keeps existing choices', async () => {
		const { def, name, size } = form()
		const wrapper = mountBody({ tableId: 3, map: { 12: '@entry' } }, def)
		await flushPromises()
		await wrapper.findAll('button').find((b) => b.text() === 'Match by name')!.trigger('click')
		expect(wrapper.emitted('patch')![0][0]).toEqual({ map: { 11: name, 12: '@entry' } })
		expect(size).toBeDefined()
	})

	it('warns when a mapped answer no longer fits its column', async () => {
		const { def, name } = form()
		// A text field cannot go in a number column.
		const wrapper = mountBody({ tableId: 3, map: { 12: name } }, def)
		await flushPromises()
		expect(wrapper.text()).toContain('no longer exist or no longer fit')
	})

	it('says so when the owner cannot add rows to the saved table', async () => {
		const { def } = form()
		const wrapper = mountBody({ tableId: 99 }, def)
		await flushPromises()
		expect(wrapper.text()).toContain('You cannot add rows to the table')
		expect(columnCalls).toEqual([])
	})

	it('says so when Tables is not available', async () => {
		tables = { available: false, tables: [] }
		const { def } = form()
		const wrapper = mountBody({ tableId: 3 }, def)
		await flushPromises()
		expect(wrapper.text()).toContain('The Tables app is not installed or not enabled')
		expect(wrapper.text()).not.toContain('Fill these columns')
	})

	it('says so when the tables cannot be loaded', async () => {
		tablesFail = true
		const { def } = form()
		const wrapper = mountBody({}, def)
		// A plain function that rejects later, so Vitest does not report the handled rejection.
		await flushPromises()
		expect(wrapper.text()).toContain('Could not load your Tables')
	})

	it('suggests creating a table when there is none to use', async () => {
		tables = { available: true, tables: [] }
		const { def } = form()
		const wrapper = mountBody({}, def)
		await flushPromises()
		expect(wrapper.text()).toContain('You have no Tables tables you can add rows to')
	})
})
