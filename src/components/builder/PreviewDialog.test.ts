// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DraftDefinition } from '../../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { emptyDefinition } from '../../builder/definition.ts'
import PreviewDialog from './PreviewDialog.vue'
import SegmentedControl from './SegmentedControl.vue'

const definition: DraftDefinition = emptyDefinition('Inquiry')

function frame(wrapper: ReturnType<typeof mount>) {
	return document.body.querySelector<HTMLElement>('.af-preview__frame')
}

describe('PreviewDialog device switch', () => {
	it('starts at full width and narrows the frame for tablet and mobile', async () => {
		const wrapper = mount(PreviewDialog, { props: { definition }, attachTo: document.body })
		expect(frame(wrapper)?.style.maxWidth).toBe('')

		const radios = Array.from(document.body.querySelectorAll<HTMLButtonElement>('.af-preview__device [role=radio]'))
		expect(radios.map((radio) => radio.getAttribute('aria-label'))).toEqual(['Desktop', 'Tablet', 'Mobile'])

		radios[2].click()
		await wrapper.vm.$nextTick()
		expect(frame(wrapper)?.style.maxWidth).toBe('390px')

		radios[1].click()
		await wrapper.vm.$nextTick()
		expect(frame(wrapper)?.style.maxWidth).toBe('768px')

		radios[0].click()
		await wrapper.vm.$nextTick()
		expect(frame(wrapper)?.style.maxWidth).toBe('')
		wrapper.unmount()
	})
})

describe('SegmentedControl icons', () => {
	const options = [
		{ value: 'a', label: 'Alpha', icon: 'M0 0h24v24H0z' },
		{ value: 'b', label: 'Beta', icon: 'M0 0h24v24H0z' },
	]

	it('keeps the label as the accessible name when only icons are shown', () => {
		const wrapper = mount(SegmentedControl, { props: { options, modelValue: 'a', groupLabel: 'Test', iconOnly: true } })
		const first = wrapper.get('[role=radio]')
		expect(first.attributes('aria-label')).toBe('Alpha')
		expect(first.find('.af-seg__label').exists()).toBe(false)
		expect(first.find('svg').exists()).toBe(true)
	})

	it('shows icon and label by default', () => {
		const wrapper = mount(SegmentedControl, { props: { options, modelValue: 'a', groupLabel: 'Test' } })
		const first = wrapper.get('[role=radio]')
		expect(first.find('svg').exists()).toBe(true)
		expect(first.find('.af-seg__label').text()).toBe('Alpha')
	})
})
