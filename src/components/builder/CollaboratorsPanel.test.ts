// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Collaborator } from '../../api/forms.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/http.ts'
import CollaboratorsPanel from './CollaboratorsPanel.vue'

const listCollaborators = vi.hoisted(() => vi.fn())
const shareForm = vi.hoisted(() => vi.fn())
const unshareForm = vi.hoisted(() => vi.fn())
const findSharees = vi.hoisted(() => vi.fn())
const transferForm = vi.hoisted(() => vi.fn())

vi.mock('../../api/forms.ts', () => ({ listCollaborators, shareForm, unshareForm, findSharees, transferForm }))

function share(id: number, patch: Partial<Collaborator> = {}): Collaborator {
	return { id, type: 'user', with: 'anna', displayName: 'Anna Lee', role: 'editor', createdAt: 1, ...patch }
}

async function mountPanel(props: Record<string, unknown> = {}) {
	const wrapper = mount(CollaboratorsPanel, { props: { formId: 5, ...props } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.useFakeTimers()
	listCollaborators.mockReset().mockResolvedValue({ owner: { id: 'olga', displayName: 'Olga Owner' }, shares: [share(1), share(2, { type: 'group', with: 'sales', displayName: 'Sales', role: 'reviewer' })] })
	shareForm.mockReset()
	unshareForm.mockReset().mockResolvedValue(undefined)
	findSharees.mockReset().mockResolvedValue([])
})

afterEach(() => {
	vi.useRealTimers()
})

async function search(wrapper: Awaited<ReturnType<typeof mountPanel>>, text: string): Promise<void> {
	await wrapper.find('input[type="text"], input:not([type])').setValue(text)
	await vi.advanceTimersByTimeAsync(300)
	await flushPromises()
}

describe('who a form is shared with', () => {
	it('lists the owner, people and groups with their roles', async () => {
		const wrapper = await mountPanel()
		const rows = wrapper.findAll('.af-collab__list li').map((row) => row.text().replace(/\s+/g, ' '))
		expect(rows[0]).toContain('Olga Owner')
		expect(rows[0]).toContain('Owner')
		expect(rows[1]).toContain('Anna Lee')
		expect(rows[2]).toContain('Sales')
		expect(rows[2]).toContain('Group')
		const selects = wrapper.findAll('.af-collab__list select')
		expect((selects[0].element as HTMLSelectElement).value).toBe('editor')
		expect((selects[1].element as HTMLSelectElement).value).toBe('reviewer')
	})

	it('says so when the list cannot be loaded, and tries again', async () => {
		listCollaborators.mockRejectedValueOnce(new Error('x'))
		const wrapper = await mountPanel()
		expect(wrapper.text()).toContain('Could not load who the form is shared with.')
		await wrapper.findAll('button').find((button) => button.text() === 'Try again')!.trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('Anna Lee')
	})

	it('searches as the owner types and shares with the one they pick, as the chosen role', async () => {
		findSharees.mockResolvedValue([{ type: 'user', with: 'bo', label: 'Bo Chen' }])
		shareForm.mockResolvedValue(share(3, { with: 'bo', displayName: 'Bo Chen', role: 'reviewer' }))
		const wrapper = await mountPanel()
		await wrapper.find('select[aria-label="Role for the new collaborator"]').setValue('reviewer')
		await search(wrapper, 'bo')
		expect(findSharees).toHaveBeenCalledWith(5, 'bo')
		await wrapper.find('.af-collab__result').trigger('click')
		await flushPromises()
		expect(shareForm).toHaveBeenCalledWith(5, 'user', 'bo', 'reviewer')
		expect(wrapper.find('.af-collab__list').text()).toContain('Bo Chen')
		// The search box is cleared, so the result is gone.
		expect(wrapper.find('.af-collab__result').exists()).toBe(false)
	})

	it('waits for typing to pause before it asks the server', async () => {
		const wrapper = await mountPanel()
		await wrapper.find('input[type="text"], input:not([type])').setValue('b')
		await vi.advanceTimersByTimeAsync(100)
		expect(findSharees).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(200)
		expect(findSharees).toHaveBeenCalledTimes(1)
	})

	it('says so when nobody is found', async () => {
		const wrapper = await mountPanel()
		await search(wrapper, 'zzz')
		expect(wrapper.text()).toContain('Nobody found.')
	})

	it('changes a role and shows what the server saved', async () => {
		shareForm.mockResolvedValue(share(1, { role: 'reviewer' }))
		const wrapper = await mountPanel()
		await wrapper.findAll('.af-collab__list select')[0].setValue('reviewer')
		await flushPromises()
		expect(shareForm).toHaveBeenCalledWith(5, 'user', 'anna', 'reviewer')
		expect((wrapper.findAll('.af-collab__list select')[0].element as HTMLSelectElement).value).toBe('reviewer')
	})

	it('removes a share', async () => {
		const wrapper = await mountPanel()
		await wrapper.find('button[aria-label="Remove Anna Lee"]').trigger('click')
		await flushPromises()
		expect(unshareForm).toHaveBeenCalledWith(5, 1)
		expect(wrapper.find('.af-collab__list').text()).not.toContain('Anna Lee')
		expect(wrapper.find('.af-collab__list').text()).toContain('Sales')
	})

	it('explains why a person or group cannot be added', async () => {
		findSharees.mockResolvedValue([{ type: 'user', with: 'gone', label: 'Gone Person' }])
		shareForm.mockRejectedValue(new ApiError(422, 'validation', [{ code: 'share_target_invalid' }] as never))
		const wrapper = await mountPanel()
		await search(wrapper, 'gone')
		await wrapper.find('.af-collab__result').trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('That account or group no longer exists.')
	})

	it('warns about files kept in the owner\'s Files only when the form has such uploads', async () => {
		expect((await mountPanel()).text()).not.toContain('kept in your own Files')
		expect((await mountPanel({ hasFilesUploads: true })).text()).toContain('kept in your own Files')
	})

	it('says that notes are shared', async () => {
		expect((await mountPanel()).text()).toContain('Notes on responses can be read by everyone this form is shared with.')
	})

	describe('handing the form over (docs/SCHEMA.md section 22.7)', () => {
		async function confirm(wrapper: Awaited<ReturnType<typeof mountPanel>>): Promise<void> {
			await wrapper.find('[data-test="hand-over"] select').setValue('anna')
			await wrapper.find('[data-test="hand-over-button"]').trigger('click')
			await flushPromises()
			const button = [...document.body.querySelectorAll('button')].find((one) => one.textContent?.trim() === 'Transfer')!
			button.click()
			await flushPromises()
		}

		afterEach(() => {
			document.body.innerHTML = ''
		})

		it('offers only accounts, not groups', async () => {
			const wrapper = await mountPanel()
			const options = wrapper.findAll('[data-test="hand-over"] option').map((option) => option.text())
			expect(options).toEqual(['Transfer form ownership to…', 'Anna Lee'])
		})

		it('asks first, then hands over and tells the builder', async () => {
			transferForm.mockResolvedValue({ id: 5, role: 'editor', ownerId: 'anna' })
			const wrapper = await mountPanel()
			await wrapper.find('[data-test="hand-over"] select').setValue('anna')
			await wrapper.find('[data-test="hand-over-button"]').trigger('click')
			await flushPromises()
			expect(document.body.textContent).toContain('Transfer this form to Anna Lee?')
			expect(transferForm).not.toHaveBeenCalled()
			;[...document.body.querySelectorAll('button')].find((one) => one.textContent?.trim() === 'Transfer')!.click()
			await flushPromises()
			expect(transferForm).toHaveBeenCalledWith(5, 'anna')
			expect(wrapper.emitted('transferred')).toHaveLength(1)
		})

		it('says why when responses hold files that would stay behind', async () => {
			transferForm.mockRejectedValue(new ApiError(422, 'validation', [{ code: 'transfer_has_files' }] as never))
			const wrapper = await mountPanel()
			await confirm(wrapper)
			expect(wrapper.text()).toContain('Some responses have files in your own Files')
			expect(wrapper.emitted('transferred')).toBeUndefined()
		})
	})
})
