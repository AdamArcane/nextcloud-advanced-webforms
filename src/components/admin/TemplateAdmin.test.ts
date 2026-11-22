// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { TemplateAdminSettings } from '../../api/admin.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { addSharedTemplate, deleteSharedTemplate, fetchAdminTemplates, renameSharedTemplate, saveHiddenTemplates } from '../../api/admin.ts'
import { listForms } from '../../api/forms.ts'
import { ApiError } from '../../api/http.ts'
import TemplateAdmin from './TemplateAdmin.vue'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../api/admin.ts', () => ({
	fetchAdminTemplates: vi.fn(),
	saveHiddenTemplates: vi.fn(),
	addSharedTemplate: vi.fn(),
	renameSharedTemplate: vi.fn(),
	deleteSharedTemplate: vi.fn(),
}))
vi.mock('../../api/forms.ts', () => ({ listForms: vi.fn() }))

function settings(over: Partial<TemplateAdminSettings> = {}): TemplateAdminSettings {
	return {
		bundled: [
			{ id: 'b:contact', category: 'general', name: 'Contact form', description: '', fieldCount: 4, hidden: false },
			{ id: 'b:quick_poll', category: 'general', name: 'Quick poll', description: '', fieldCount: 2, hidden: false },
		],
		shared: [{ id: 's:3', name: 'Onboarding', fieldCount: 9, updatedAt: 1 }],
		...over,
	}
}

async function mountIt() {
	const wrapper = mount(TemplateAdmin)
	await flushPromises()
	return wrapper
}

const button = (wrapper: Awaited<ReturnType<typeof mountIt>>, text: string) => wrapper.findAll('button').find((b) => b.text() === text)!

const nameValues = (wrapper: Awaited<ReturnType<typeof mountIt>>) => wrapper.findAll('input.input-field__input').slice(0, -1).map((input) => (input.element as HTMLInputElement).value)

beforeEach(() => {
	vi.mocked(fetchAdminTemplates).mockReset().mockResolvedValue(settings())
	vi.mocked(saveHiddenTemplates).mockReset()
	vi.mocked(addSharedTemplate).mockReset()
	vi.mocked(renameSharedTemplate).mockReset()
	vi.mocked(deleteSharedTemplate).mockReset()
	vi.mocked(listForms).mockReset().mockResolvedValue([{ id: 12, title: 'Intake' }] as never)
})

describe('TemplateAdmin', () => {
	it('shows every starter as switched on and the shared templates', async () => {
		const wrapper = await mountIt()
		const boxes = wrapper.findAll('input[type="checkbox"]')
		expect(boxes).toHaveLength(2)
		expect(boxes.every((box) => (box.element as HTMLInputElement).checked)).toBe(true)
		expect(nameValues(wrapper)).toEqual(['Onboarding'])
		expect(button(wrapper, 'Save').attributes('disabled')).toBeDefined()
	})

	it('hides a starter by switching it off', async () => {
		vi.mocked(saveHiddenTemplates).mockResolvedValue(settings({ bundled: settings().bundled.map((item) => ({ ...item, hidden: item.id === 'b:quick_poll' })) }))
		const wrapper = await mountIt()
		await wrapper.findAll('input[type="checkbox"]')[1]!.setValue(false)
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()
		expect(saveHiddenTemplates).toHaveBeenCalledWith(['b:quick_poll'])
		expect((wrapper.findAll('input[type="checkbox"]')[1]!.element as HTMLInputElement).checked).toBe(false)
	})

	it('adds a template from one of the administrator\'s forms', async () => {
		vi.mocked(addSharedTemplate).mockResolvedValue(settings())
		const wrapper = await mountIt()
		expect(button(wrapper, 'Add template').attributes('disabled')).toBeDefined()
		await wrapper.find('select').setValue('12')
		await button(wrapper, 'Add template').trigger('click')
		await flushPromises()
		expect(addSharedTemplate).toHaveBeenCalledWith(12, '')
	})

	it('renames and deletes a shared template', async () => {
		vi.mocked(renameSharedTemplate).mockResolvedValue(settings())
		vi.mocked(deleteSharedTemplate).mockResolvedValue(settings({ shared: [] }))
		const wrapper = await mountIt()
		await wrapper.find('input.input-field__input').setValue('Staff onboarding')
		await button(wrapper, 'Rename').trigger('click')
		await flushPromises()
		expect(renameSharedTemplate).toHaveBeenCalledWith('s:3', 'Staff onboarding')
		await button(wrapper, 'Delete').trigger('click')
		await flushPromises()
		expect(deleteSharedTemplate).toHaveBeenCalledWith('s:3')
		expect(wrapper.text()).toContain('There are no shared templates yet.')
	})

	it('keeps what is on screen when a save fails', async () => {
		vi.mocked(addSharedTemplate).mockRejectedValue(new ApiError(422, 'validation', [{ code: 'template_limit' }]))
		const wrapper = await mountIt()
		await wrapper.find('select').setValue('12')
		await button(wrapper, 'Add template').trigger('click')
		await flushPromises()
		expect(nameValues(wrapper)).toEqual(['Onboarding'])
	})

	it('says so when the templates cannot be loaded', async () => {
		vi.mocked(fetchAdminTemplates).mockRejectedValue(new Error('offline'))
		const wrapper = await mountIt()
		expect(wrapper.text()).toContain('Could not load the templates.')
	})
})
