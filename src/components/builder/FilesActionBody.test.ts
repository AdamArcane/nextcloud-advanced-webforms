// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import FilesActionBody from './FilesActionBody.vue'

vi.mock('@nextcloud/dialogs', () => ({ getFilePickerBuilder: vi.fn() }))

function mountBody(path: string) {
	return mount(FilesActionBody, { props: { action: { id: 'act_sheet', type: 'files', path } } })
}

describe('FilesActionBody', () => {
	it('stores a typed path without slashes at the ends', async () => {
		const wrapper = mountBody('Webforms/Leads.csv')
		await wrapper.find('input').setValue('/Sales/Leads.csv/')
		expect(wrapper.emitted('patch')?.at(-1)).toEqual([{ path: 'Sales/Leads.csv' }])
	})

	it('keeps an unusable path as typed and says what is wrong', async () => {
		const wrapper = mountBody('Leads.txt')
		expect(wrapper.text()).toContain('ends in .csv')
		await wrapper.find('input').setValue('notes.txt')
		expect(wrapper.emitted('patch')?.at(-1)).toEqual([{ path: 'notes.txt' }])
	})

	it('shows no problem for a good path', () => {
		expect(mountBody('Webforms/Leads.csv').text()).not.toContain('ends in .csv')
	})
})
