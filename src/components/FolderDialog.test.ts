// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Folder } from '../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createFolder, renameFolder } from '../api/folders.ts'
import { ApiError } from '../api/http.ts'
import FolderDialog from './FolderDialog.vue'

vi.mock('../api/folders.ts', () => ({ createFolder: vi.fn(), renameFolder: vi.fn() }))

async function mountDialog(folder: Folder | null = null) {
	const wrapper = mount(FolderDialog, { props: { open: true, folder }, global: { stubs: { teleport: true } } })
	await flushPromises()
	return wrapper
}

const button = (wrapper: Awaited<ReturnType<typeof mountDialog>>, text: string) => wrapper.findAll('button').find((el) => el.text() === text)!
const input = (wrapper: Awaited<ReturnType<typeof mountDialog>>) => wrapper.find('input.input-field__input')

beforeEach(() => {
	vi.mocked(createFolder).mockReset()
	vi.mocked(renameFolder).mockReset()
})

describe('folder dialog', () => {
	it('will not make a folder with no name', async () => {
		const wrapper = await mountDialog()
		await input(wrapper).setValue('   ')
		expect(button(wrapper, 'Create folder').attributes('disabled')).toBeDefined()
	})

	it('makes a folder when Enter is pressed, then closes', async () => {
		vi.mocked(createFolder).mockResolvedValue({ id: 1, name: 'Events' })
		const wrapper = await mountDialog()
		await input(wrapper).setValue('Events')
		await input(wrapper).trigger('keydown', { key: 'Enter' })
		await flushPromises()
		expect(createFolder).toHaveBeenCalledWith('Events')
		expect(wrapper.emitted('saved')).toEqual([[{ id: 1, name: 'Events' }]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('renames the folder it was given', async () => {
		vi.mocked(renameFolder).mockResolvedValue({ id: 4, name: 'Work' })
		const wrapper = await mountDialog({ id: 4, name: 'Wrok' })
		expect((input(wrapper).element as HTMLInputElement).value).toBe('Wrok')
		await input(wrapper).setValue('Work')
		await button(wrapper, 'Rename').trigger('click')
		await flushPromises()
		expect(renameFolder).toHaveBeenCalledWith(4, 'Work')
	})

	it('says why the server refused a name, and stays open', async () => {
		vi.mocked(createFolder).mockRejectedValue(new ApiError(422, 'validation', [{ code: 'folder_name_taken' }]))
		const wrapper = await mountDialog()
		await input(wrapper).setValue('Events')
		await button(wrapper, 'Create folder').trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('You already have a folder with this name.')
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})
})
