// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import OptionsEditor from './OptionsEditor.vue'

describe('OptionsEditor lists', () => {
	it('offers the ready-made lists and replaces the options with the one picked', async () => {
		const wrapper = mount(OptionsEditor, { props: { options: [{ value: 'opt_aaaaaa', label: 'One' }] }, attachTo: document.body })
		const actions = wrapper.findComponent({ name: 'NcActions' })
		expect(actions.exists()).toBe(true)
		await wrapper.find('button[aria-haspopup="menu"], button.action-item__menutoggle').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 50))
		const buttons = wrapper.findAllComponents({ name: 'NcActionButton' })
		expect(buttons.length).toBe(8)
		await buttons[1].vm.$emit('click')
		const emitted = wrapper.emitted('update:options')![0][0] as { value: string }[]
		expect(emitted.length).toBe(51)
		expect(emitted.some((o) => o.value === 'us_tx')).toBe(true)
		wrapper.unmount()
	})
})
