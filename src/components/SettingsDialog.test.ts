// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { UserPreferences } from '../api/settings.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { DEFAULT_PREFERENCES } from '../api/settings.ts'
import SettingsDialog from './SettingsDialog.vue'

function mountDialog(preferences: Partial<UserPreferences> = {}, loaded = true) {
	return mount(SettingsDialog, {
		props: { open: true, preferences: { ...DEFAULT_PREFERENCES, ...preferences }, loaded },
		global: { stubs: { teleport: true } },
	})
}

type Wrapper = ReturnType<typeof mountDialog>

/** The switch with this text beside it. */
function switchFor(wrapper: Wrapper, text: string) {
	const row = wrapper.findAll('.checkbox-radio-switch').find((el) => el.text().includes(text))
	expect(row, text).toBeDefined()
	return row!.find('input[type="checkbox"]')
}

const segment = (wrapper: Wrapper, text: string) => wrapper.findAll('button[role="radio"]').find((el) => el.text() === text)!

describe('settings dialog', () => {
	it('shows what is saved', () => {
		const wrapper = mountDialog({ openPublishedOnResponses: false, newFormHideTitle: true, newFormCaptcha: false, responsesSummary: false, newFormLabelPosition: 'inline', responsesView: 'cards' })
		expect((switchFor(wrapper, 'Open published forms').element as HTMLInputElement).checked).toBe(false)
		expect((switchFor(wrapper, 'Leave the title off').element as HTMLInputElement).checked).toBe(true)
		expect((switchFor(wrapper, 'spam check').element as HTMLInputElement).checked).toBe(false)
		expect((switchFor(wrapper, 'summary above').element as HTMLInputElement).checked).toBe(false)
		expect(segment(wrapper, 'Beside').classes()).toContain('af-seg__item--on')
		expect(segment(wrapper, 'Cards').classes()).toContain('af-seg__item--on')
		expect(wrapper.find('input[type="color"]').exists()).toBe(true)
	})

	it('says what was changed, and only that', async () => {
		const wrapper = mountDialog()
		await switchFor(wrapper, 'Open published forms').setValue(false)
		await switchFor(wrapper, 'Leave the title off').setValue(true)
		await switchFor(wrapper, 'spam check').setValue(false)
		await switchFor(wrapper, 'summary above').setValue(false)
		await segment(wrapper, 'Beside').trigger('click')
		await segment(wrapper, 'Cards').trigger('click')
		await wrapper.find('input[type="color"]').setValue('#aa00ff')
		expect(wrapper.emitted('change')).toEqual([
			[{ openPublishedOnResponses: false }],
			[{ newFormHideTitle: true }],
			[{ newFormCaptcha: false }],
			[{ responsesSummary: false }],
			[{ newFormLabelPosition: 'inline' }],
			[{ responsesView: 'cards' }],
			[{ newFormAccentColor: '#aa00ff' }],
		])
	})

	it('offers to go back to the Nextcloud colour only when a colour is chosen', async () => {
		const plain = mountDialog()
		expect(plain.text()).toContain('The Nextcloud colour')
		expect(plain.findAll('button').some((b) => b.text() === 'Use the Nextcloud colour')).toBe(false)

		const chosen = mountDialog({ newFormAccentColor: '#112233' })
		expect(chosen.text()).toContain('#112233')
		await chosen.findAll('button').find((b) => b.text() === 'Use the Nextcloud colour')!.trigger('click')
		expect(chosen.emitted('change')).toEqual([[{ newFormAccentColor: '' }]])
	})

	it('keeps everything off until the preferences have been read', async () => {
		const wrapper = mountDialog({}, false)
		for (const text of ['Open published forms', 'Leave the title off', 'spam check', 'summary above']) {
			expect(switchFor(wrapper, text).attributes('disabled'), text).toBeDefined()
		}
		expect(wrapper.find('input[type="color"]').attributes('disabled')).toBeDefined()
		await segment(wrapper, 'Beside').trigger('click')
		await segment(wrapper, 'Cards').trigger('click')
		expect(wrapper.emitted('change')).toBeUndefined()
	})

	it('offers a download of every form as a link', () => {
		const link = mountDialog().find('a[href$="/api/v1/backup"]')
		expect(link.exists()).toBe(true)
		expect(link.attributes('download')).toBeDefined()
		expect(link.text()).toContain('Download backup of all my forms')
	})
})
