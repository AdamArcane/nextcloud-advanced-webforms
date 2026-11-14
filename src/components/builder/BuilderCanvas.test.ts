// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { addField, emptyDefinition } from '../../builder/definition.ts'
import BuilderCanvas from './BuilderCanvas.vue'

/** happy-dom has no layout, so give the panel, rows and cells fixed boxes: rows 100 high with 12 between. */
function fakeLayout(): void {
	vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
		const box = (left: number, top: number, width: number, height: number) => ({ left, top, width, height, right: left + width, bottom: top + height, x: left, y: top, toJSON: () => ({}) }) as DOMRect
		if (this.getAttribute('role') === 'tabpanel') {
			return box(0, 0, 600, 400)
		}
		const row = this.classList.contains('af-row') ? this : this.closest<HTMLElement>('.af-row')
		if (!row) {
			return box(0, 0, 0, 0)
		}
		const index = [...row.parentElement!.querySelectorAll('.af-row')].indexOf(row)
		const top = index * 112
		if (this === row) {
			return box(0, top, 600, 100)
		}
		return box(0, top, 600, 100)
	})
}

function drag(target: Element, type: string, x = 0, y = 0): void {
	const event = new MouseEvent(type, { bubbles: true, cancelable: true, clientX: x, clientY: y })
	target.dispatchEvent(event)
}

afterEach(() => vi.restoreAllMocks())

describe('dragging onto a row', () => {
	it('drops beside the field under the pointer, not into a row of its own', async () => {
		let def = emptyDefinition('T')
		def = addField(def, 'text', { kind: 'row', index: 0 }).definition
		def = addField(def, 'email', { kind: 'row', index: 1 }).definition
		fakeLayout()
		const wrapper = mount(BuilderCanvas, {
			attachTo: document.body,
			props: { definition: def, selectedId: null, errors: [], mobile: false },
		})
		const cells = wrapper.findAll('.af-cell')
		const panel = wrapper.find('[role="tabpanel"]').element
		drag(cells[1].element, 'dragstart')
		await wrapper.vm.$nextTick()
		drag(panel, 'dragover', 500, 50)
		await wrapper.vm.$nextTick()
		drag(panel, 'drop', 500, 50)
		const emitted = wrapper.emitted('dropField')!
		expect(emitted[0][1]).toMatchObject({ kind: 'cell', side: 'after' })
	})
})

describe('field toolbar', () => {
	it('duplicates and deletes the field it sits on, without selecting it', async () => {
		let def = emptyDefinition('T')
		const a = addField(def, 'text', { kind: 'row', index: 0 })
		def = addField(a.definition, 'email', { kind: 'row', index: 1 }).definition
		const wrapper = mount(BuilderCanvas, {
			props: { definition: def, selectedId: null, errors: [], mobile: false },
		})
		const second = wrapper.findAll('.af-cell')[1]
		await second.find('button[aria-label="Duplicate field"]').trigger('click')
		await second.find('button[aria-label="Delete field"]').trigger('click')
		const secondId = Object.keys(def.fields)[1]
		expect(wrapper.emitted('duplicate')![0]).toEqual([secondId])
		expect(wrapper.emitted('remove')![0]).toEqual([secondId])
		expect(wrapper.emitted('select')).toBeUndefined()
	})
})
