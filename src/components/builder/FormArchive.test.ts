// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { emptyDefinition } from '../../builder/definition.ts'
import FormProperties from './FormProperties.vue'

function mountProps(archived: boolean) {
	return mount(FormProperties, { props: { section: 'general' as const, definition: emptyDefinition('T'), shareUrl: null, archived } })
}

describe('archiving in the form settings', () => {
	it('offers to archive a form that is not archived', async () => {
		const wrapper = mountProps(false)
		const button = wrapper.findAll('button').find((node) => node.text() === 'Archive form')!
		expect(button).toBeDefined()
		await button.trigger('click')
		expect(wrapper.emitted('archive')).toHaveLength(1)
	})

	it('offers to restore an archived form', async () => {
		const wrapper = mountProps(true)
		expect(wrapper.findAll('button').some((node) => node.text() === 'Archive form')).toBe(false)
		const button = wrapper.findAll('button').find((node) => node.text() === 'Restore form')!
		await button.trigger('click')
		expect(wrapper.emitted('archive')).toHaveLength(1)
		expect(wrapper.text()).toContain('This form is archived')
	})
})
