// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { FIELD_GROUPS, FIELD_TYPES } from '../../builder/definition.ts'
import { fieldTypeName } from '../../builder/fieldTypeNames.ts'
import AddFieldMenu from './AddFieldMenu.vue'

describe('field types for the add menu', () => {
	it('puts every type in a group and gives it a readable name', () => {
		for (const info of FIELD_TYPES) {
			expect(FIELD_GROUPS).toContain(info.group)
			expect(fieldTypeName(info.type)).toBe(info.label)
		}
	})

	it('has no two types with the same name', () => {
		const names = FIELD_TYPES.map((info) => fieldTypeName(info.type))
		expect(new Set(names).size).toBe(names.length)
	})
})

describe('add field menu', () => {
	async function opened() {
		const wrapper = mount(AddFieldMenu, { attachTo: document.body, props: { label: 'Add field' } })
		await wrapper.find('button').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 50))
		return wrapper
	}

	it('lists the types by group and picks one', async () => {
		const wrapper = await opened()
		const body = document.body
		expect(body.textContent).toContain('Choices')
		expect(body.textContent).toContain('File upload')
		const item = [...body.querySelectorAll<HTMLButtonElement>('.af-add__item')].find((button) => button.textContent?.includes('File upload'))!
		item.click()
		expect(wrapper.emitted('pick')![0]).toEqual(['file'])
		wrapper.unmount()
	})

	it('filters by what is typed and picks the first match on Enter', async () => {
		const wrapper = await opened()
		const search = document.body.querySelector<HTMLInputElement>('.af-add__search')!
		search.value = 'upl'
		search.dispatchEvent(new Event('input'))
		await new Promise((resolve) => setTimeout(resolve, 10))
		expect(document.body.querySelectorAll('.af-add__item')).toHaveLength(1)
		search.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
		expect(wrapper.emitted('pick')![0]).toEqual(['file'])
		wrapper.unmount()
	})
})
