// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ActionType } from '../../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AddActionMenu from './AddActionMenu.vue'

async function opened(types: ActionType[]) {
	const wrapper = mount(AddActionMenu, { attachTo: document.body, props: { types } })
	await wrapper.find('button').trigger('click')
	await new Promise((resolve) => setTimeout(resolve, 50))
	return wrapper
}

describe('add action menu', () => {
	it('groups the types by when they happen and describes each', async () => {
		const wrapper = await opened(['message', 'redirect', 'email', 'files'])
		const groups = [...document.body.querySelectorAll('.af-add-action__group')].map((group) => ({
			name: group.querySelector('h3')?.textContent?.trim(),
			items: [...group.querySelectorAll('.af-add-action__name')].map((item) => item.textContent),
		}))
		expect(groups).toEqual([
			{ name: 'On the page', items: ['Show a message', 'Redirect'] },
			{ name: 'In the background', items: ['Send an email', 'Add a line to a CSV file'] },
		])
		expect(document.body.textContent).toContain('one line each')
		wrapper.unmount()
	})

	it('leaves out a group with nothing in it, and picks a type', async () => {
		const wrapper = await opened(['webhook'])
		expect(document.body.textContent).not.toContain('On the page')
		document.body.querySelector<HTMLButtonElement>('.af-add-action__item')!.click()
		expect(wrapper.emitted('pick')![0]).toEqual(['webhook'])
		wrapper.unmount()
	})

	it('cannot be opened when disabled or empty', () => {
		expect(mount(AddActionMenu, { props: { types: ['message'], disabled: true } }).find('button').attributes('disabled')).toBeDefined()
		expect(mount(AddActionMenu, { props: { types: [] } }).find('button').attributes('disabled')).toBeDefined()
	})
})
