// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createFromTemplate, deleteTemplate, fetchTemplates } from '../api/forms.ts'
import { ApiError } from '../api/http.ts'
import TemplateDialog from './TemplateDialog.vue'

vi.mock('../api/forms.ts', () => ({
	fetchTemplates: vi.fn(),
	createFromTemplate: vi.fn(),
	deleteTemplate: vi.fn(),
}))

const list = {
	bundled: [
		{ id: 'b:contact', category: 'general', name: 'Contact form', description: 'Name, email and a message.', fieldCount: 4 },
		{ id: 'b:event_rsvp', category: 'events', name: 'Event RSVP', description: 'Count guests.', fieldCount: 6 },
	],
	shared: [{ id: 's:3', name: 'Company onboarding', fieldCount: 9, updatedAt: 1 }],
	own: [{ id: 'u:7', name: 'My intake form', fieldCount: 3, updatedAt: 1 }],
}

async function mountDialog(canCreate = true) {
	const wrapper = mount(TemplateDialog, {
		props: { open: true, canCreate },
		global: { stubs: { teleport: true } },
	})
	await flushPromises()
	return wrapper
}

const button = (wrapper: Awaited<ReturnType<typeof mountDialog>>, text: string) => wrapper.findAll('button').find((el) => el.text().includes(text))!

beforeEach(() => {
	vi.mocked(fetchTemplates).mockReset().mockResolvedValue(list)
	vi.mocked(createFromTemplate).mockReset()
	vi.mocked(deleteTemplate).mockReset()
})

describe('template dialog', () => {
	it('offers a blank form, the saved templates and the starters by category', async () => {
		const wrapper = await mountDialog()
		const text = wrapper.text()
		expect(text).toContain('Blank form')
		expect(text).toContain('My templates')
		expect(text).toContain('My intake form')
		expect(text).toContain('Contact form')
		expect(text).toContain('Event RSVP')
		expect(text.indexOf('General')).toBeLessThan(text.indexOf('Events'))
	})

	it('lists the templates made for everyone, with no delete button', async () => {
		vi.mocked(createFromTemplate).mockResolvedValue({ form: { id: 57 }, errors: [] } as never)
		const wrapper = await mountDialog()
		expect(wrapper.text()).toContain('Shared templates')
		expect(wrapper.text()).toContain('Company onboarding')
		// Only the person's own template can be deleted here.
		expect(wrapper.findAll('button').filter((el) => el.text() === 'Delete')).toHaveLength(1)
		await button(wrapper, 'Company onboarding').trigger('click')
		await flushPromises()
		expect(vi.mocked(createFromTemplate).mock.calls[0]![0]).toBe('s:3')
		expect(vi.mocked(createFromTemplate).mock.calls[0]![2]).toBeUndefined()
	})

	it('hands a blank form back to the screen', async () => {
		const wrapper = await mountDialog()
		await button(wrapper, 'Blank form').trigger('click')
		expect(wrapper.emitted('blank')).toHaveLength(1)
		expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
		expect(createFromTemplate).not.toHaveBeenCalled()
	})

	it('makes a form from a starter with the words in the page language', async () => {
		const form = { id: 55 }
		vi.mocked(createFromTemplate).mockResolvedValue({ form, errors: [] } as never)
		const wrapper = await mountDialog()
		await button(wrapper, 'Contact form').trigger('click')
		await flushPromises()
		const [id, title, strings] = vi.mocked(createFromTemplate).mock.calls[0]!
		expect(id).toBe('b:contact')
		expect(title).toBe('')
		expect(strings).toBeDefined()
		expect(wrapper.emitted('created')?.[0]).toEqual([form])
	})

	it('sends no translation map for a template the person saved', async () => {
		vi.mocked(createFromTemplate).mockResolvedValue({ form: { id: 56 }, errors: [] } as never)
		const wrapper = await mountDialog()
		await button(wrapper, 'My intake form').trigger('click')
		await flushPromises()
		expect(vi.mocked(createFromTemplate).mock.calls[0]![0]).toBe('u:7')
		expect(vi.mocked(createFromTemplate).mock.calls[0]![2]).toBeUndefined()
	})

	it('keeps the dialog open and says so when the form could not be made', async () => {
		vi.mocked(createFromTemplate).mockRejectedValue(new ApiError(422, 'validation', [{ code: 'create_not_allowed' }]))
		const wrapper = await mountDialog()
		await button(wrapper, 'Contact form').trigger('click')
		await flushPromises()
		expect(wrapper.emitted('created')).toBeUndefined()
		expect(wrapper.find('.notecard').exists()).toBe(true)
	})

	it('deletes one of the saved templates', async () => {
		vi.mocked(deleteTemplate).mockResolvedValue()
		const wrapper = await mountDialog()
		await button(wrapper, 'Delete').trigger('click')
		await flushPromises()
		expect(deleteTemplate).toHaveBeenCalledWith('u:7')
		expect(wrapper.text()).not.toContain('My intake form')
	})

	it('still allows a blank form when the templates cannot be loaded', async () => {
		vi.mocked(fetchTemplates).mockRejectedValue(new Error('offline'))
		const wrapper = await mountDialog()
		expect(wrapper.text()).toContain('could not be loaded')
		expect(button(wrapper, 'Blank form').attributes('disabled')).toBeUndefined()
	})

	it('disables everything for a person who may not create forms', async () => {
		const wrapper = await mountDialog(false)
		expect(button(wrapper, 'Blank form').attributes('disabled')).toBeDefined()
		expect(button(wrapper, 'Contact form').attributes('disabled')).toBeDefined()
	})
})
