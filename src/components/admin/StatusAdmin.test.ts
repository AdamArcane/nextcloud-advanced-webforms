// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { StatusAdminSettings } from '../../api/admin.ts'
import type { ResponseStatus } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/http.ts'
import StatusAdmin from './StatusAdmin.vue'

const COLORS = ['gray', 'red', 'orange', 'yellow', 'green', 'teal', 'blue', 'purple']
let current: ResponseStatus[]
let loadFails = false
/** How many responses carry a status that a save takes out, so the server asks first. */
let affected = 0
const saves: { statuses: ResponseStatus[], confirmDelete: boolean }[] = []

function settings(): StatusAdminSettings {
	return { statuses: current, colors: COLORS, max: 20, maxLabel: 40 }
}

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../api/admin.ts', () => ({
	fetchStatuses: () => (loadFails ? Promise.reject(new Error('x')) : Promise.resolve(settings())),
	saveStatuses: (statuses: ResponseStatus[], confirmDelete = false) => {
		saves.push({ statuses, confirmDelete })
		if (affected > 0 && !confirmDelete) {
			return new Promise((_resolve, reject) => setTimeout(() => reject(new ApiError(409, 'confirmation_required', [], null, { affected })), 0))
		}
		current = statuses
		return Promise.resolve(settings())
	},
}))

async function mountIt() {
	const wrapper = mount(StatusAdmin)
	await flushPromises()
	return wrapper
}

function button(wrapper: Awaited<ReturnType<typeof mountIt>>, text: string) {
	return wrapper.findAll('button').find((b) => b.text() === text)!
}

const names = (wrapper: Awaited<ReturnType<typeof mountIt>>) => wrapper.findAll('input.af-statuses__name').map((input) => (input.element as HTMLInputElement).value)

describe('StatusAdmin', () => {
	beforeEach(() => {
		current = [
			{ id: 'in_progress', label: 'In progress', color: 'blue' },
			{ id: 'done', label: 'Done', color: 'green' },
			{ id: 'spam', label: 'Spam', color: 'gray' },
		]
		loadFails = false
		affected = 0
		saves.length = 0
	})

	it('lists the statuses in their order', async () => {
		const wrapper = await mountIt()
		expect(names(wrapper)).toEqual(['In progress', 'Done', 'Spam'])
	})

	it('says so when the settings cannot be loaded', async () => {
		loadFails = true
		const wrapper = await mountIt()
		expect(wrapper.text()).toContain('Could not load the settings.')
		expect(wrapper.find('input.af-statuses__name').exists()).toBe(false)
	})

	it('adds a status with an id made from its name and a colour not in use, and keeps the ids of the others', async () => {
		const wrapper = await mountIt()
		await button(wrapper, 'Add a status').trigger('click')
		// A status without a name cannot be saved.
		expect(button(wrapper, 'Save').attributes('disabled')).toBeDefined()
		await wrapper.findAll('input.af-statuses__name')[3].setValue(' Called back ')
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()
		expect(saves[0].statuses).toEqual([
			{ id: 'in_progress', label: 'In progress', color: 'blue' },
			{ id: 'done', label: 'Done', color: 'green' },
			{ id: 'spam', label: 'Spam', color: 'gray' },
			{ id: 'called_back', label: 'Called back', color: 'red' },
		])
	})

	it('renames a status without changing its id, so responses keep it', async () => {
		const wrapper = await mountIt()
		await wrapper.findAll('input.af-statuses__name')[1].setValue('Finished')
		await wrapper.findAll('select.af-statuses__color')[1].setValue('teal')
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()
		expect(saves[0].statuses[1]).toEqual({ id: 'done', label: 'Finished', color: 'teal' })
	})

	it('gives two new statuses with the same start different ids, and refuses the same name twice', async () => {
		const wrapper = await mountIt()
		await button(wrapper, 'Add a status').trigger('click')
		await wrapper.findAll('input.af-statuses__name')[3].setValue('Done')
		expect(wrapper.text()).toContain('Two statuses have the same name.')
		expect(button(wrapper, 'Save').attributes('disabled')).toBeDefined()
		await wrapper.findAll('input.af-statuses__name')[3].setValue('Done!')
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()
		expect(saves[0].statuses.map((status) => status.id)).toEqual(['in_progress', 'done', 'spam', 'done_2'])
	})

	it('moves a status up and down', async () => {
		const wrapper = await mountIt()
		await wrapper.find('button[aria-label="Move status 3 up"]').trigger('click')
		expect(names(wrapper)).toEqual(['In progress', 'Spam', 'Done'])
		await wrapper.find('button[aria-label="Move status 1 down"]').trigger('click')
		expect(names(wrapper)).toEqual(['Spam', 'In progress', 'Done'])
		expect(wrapper.find('button[aria-label="Move status 1 up"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('button[aria-label="Move status 3 down"]').attributes('disabled')).toBeDefined()
	})

	it('asks before deleting a status that responses have, and saves only after a yes', async () => {
		affected = 12
		const wrapper = await mountIt()
		await wrapper.find('button[aria-label="Delete status 3"]').trigger('click')
		await button(wrapper, 'Save').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 5))
		await flushPromises()
		expect(saves).toHaveLength(1)
		expect(saves[0].confirmDelete).toBe(false)
		expect(wrapper.text()).toContain('12 responses have a status you deleted.')
		// Nothing was saved yet: the list on screen is still the edited one.
		expect(names(wrapper)).toEqual(['In progress', 'Done'])

		await button(wrapper, 'Save anyway').trigger('click')
		await flushPromises()
		expect(saves[1]).toEqual({ statuses: current, confirmDelete: true })
		expect(current.map((status) => status.id)).toEqual(['in_progress', 'done'])
		expect(wrapper.text()).not.toContain('have a status you deleted')
	})

	it('can be emptied, and then says responses show no status', async () => {
		const wrapper = await mountIt()
		for (let n = 0; n < 3; n++) {
			await wrapper.find('button[aria-label="Delete status 1"]').trigger('click')
		}
		expect(wrapper.text()).toContain('There are no statuses, so responses show none.')
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()
		expect(saves[0].statuses).toEqual([])
	})
})
