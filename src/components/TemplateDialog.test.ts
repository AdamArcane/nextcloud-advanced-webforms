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
const select = (wrapper: Awaited<ReturnType<typeof mountDialog>>) => wrapper.findComponent({ name: 'NcSelect' })
type Choice = { id: string, label: string, hint: string }
const choices = (wrapper: Awaited<ReturnType<typeof mountDialog>>) => select(wrapper).props('options') as Choice[]
/** Picks a line from the dropdown, as the person would. */
async function pick(wrapper: Awaited<ReturnType<typeof mountDialog>>, id: string) {
	select(wrapper).vm.$emit('update:modelValue', choices(wrapper).find((choice) => choice.id === id))
	await flushPromises()
}

beforeEach(() => {
	vi.mocked(fetchTemplates).mockReset().mockResolvedValue(list)
	vi.mocked(createFromTemplate).mockReset()
	vi.mocked(deleteTemplate).mockReset()
})

describe('template dialog', () => {
	it('is one dropdown that starts on a blank form', async () => {
		const wrapper = await mountDialog()
		expect(wrapper.findAllComponents({ name: 'NcSelect' })).toHaveLength(1)
		expect(select(wrapper).props('modelValue')).toMatchObject({ id: 'blank' })
	})

	it('lists every choice with a name and a second line', async () => {
		const wrapper = await mountDialog()
		expect(choices(wrapper).map((choice) => choice.id)).toEqual(['blank', 's:3', 'u:7', 'b:contact', 'b:event_rsvp'])
		for (const choice of choices(wrapper)) {
			expect(choice.label, choice.id).not.toBe('')
			expect(choice.hint, choice.id).not.toBe('')
		}
		expect(choices(wrapper).find((choice) => choice.id === 'b:contact')).toMatchObject({ label: 'Contact form', hint: 'Name, email and a message.' })
		expect(choices(wrapper).find((choice) => choice.id === 'u:7')!.hint).toContain('My template')
		expect(choices(wrapper).find((choice) => choice.id === 's:3')!.hint).toContain('Shared template')
	})

	it('hands a blank form back to the screen', async () => {
		const wrapper = await mountDialog()
		await button(wrapper, 'Create form').trigger('click')
		expect(wrapper.emitted('blank')).toHaveLength(1)
		expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
		expect(createFromTemplate).not.toHaveBeenCalled()
	})

	it('makes a form from a starter with the words in the page language', async () => {
		const form = { id: 55 }
		vi.mocked(createFromTemplate).mockResolvedValue({ form, errors: [] } as never)
		const wrapper = await mountDialog()
		await pick(wrapper, 'b:contact')
		await button(wrapper, 'Create form').trigger('click')
		await flushPromises()
		const [id, title, strings] = vi.mocked(createFromTemplate).mock.calls[0]!
		expect(id).toBe('b:contact')
		expect(title).toBe('')
		expect(strings).toBeDefined()
		expect(wrapper.emitted('created')?.[0]).toEqual([form])
	})

	it('sends no translation map for a template made for everyone or saved by the person', async () => {
		vi.mocked(createFromTemplate).mockResolvedValue({ form: { id: 56 }, errors: [] } as never)
		const wrapper = await mountDialog()
		await pick(wrapper, 's:3')
		await button(wrapper, 'Create form').trigger('click')
		await flushPromises()
		await pick(wrapper, 'u:7')
		await button(wrapper, 'Create form').trigger('click')
		await flushPromises()
		const calls = vi.mocked(createFromTemplate).mock.calls
		expect(calls.map((call) => call[0])).toEqual(['s:3', 'u:7'])
		expect(calls.every((call) => call[2] === undefined)).toBe(true)
	})

	it('keeps the dialog open and says so when the form could not be made', async () => {
		vi.mocked(createFromTemplate).mockRejectedValue(new ApiError(422, 'validation', [{ code: 'create_not_allowed' }]))
		const wrapper = await mountDialog()
		await pick(wrapper, 'b:contact')
		await button(wrapper, 'Create form').trigger('click')
		await flushPromises()
		expect(wrapper.emitted('created')).toBeUndefined()
		expect(wrapper.find('.notecard').exists()).toBe(true)
	})

	it('offers to delete only the person\'s own templates', async () => {
		vi.mocked(deleteTemplate).mockResolvedValue()
		const wrapper = await mountDialog()
		expect(button(wrapper, 'Delete template')).toBeUndefined()
		await pick(wrapper, 's:3')
		expect(button(wrapper, 'Delete template')).toBeUndefined()
		await pick(wrapper, 'u:7')
		await button(wrapper, 'Delete template').trigger('click')
		await flushPromises()
		expect(deleteTemplate).toHaveBeenCalledWith('u:7')
		expect(choices(wrapper).map((choice) => choice.id)).not.toContain('u:7')
		expect(select(wrapper).props('modelValue')).toMatchObject({ id: 'blank' })
	})

	it('still allows a blank form when the templates cannot be loaded', async () => {
		vi.mocked(fetchTemplates).mockRejectedValue(new Error('offline'))
		const wrapper = await mountDialog()
		expect(wrapper.text()).toContain('could not be loaded')
		expect(choices(wrapper).map((choice) => choice.id)).toEqual(['blank'])
		expect(button(wrapper, 'Create form').attributes('disabled')).toBeUndefined()
	})

	it('disables everything for a person who may not create forms', async () => {
		const wrapper = await mountDialog(false)
		expect(select(wrapper).props('disabled')).toBe(true)
		expect(button(wrapper, 'Create form').attributes('disabled')).toBeDefined()
	})

	it('is a normal dialog on a wide screen and a small, content-height one on a phone', async () => {
		const wrapper = await mountDialog()
		expect(wrapper.findComponent({ name: 'NcDialog' }).props('size')).toBe('normal')
		wrapper.unmount()

		const original = window.matchMedia
		window.matchMedia = ((query: string) => ({ matches: true, media: query, addEventListener: vi.fn(), removeEventListener: vi.fn() })) as unknown as typeof window.matchMedia
		try {
			const phone = await mountDialog()
			expect(phone.findComponent({ name: 'NcDialog' }).props('size')).toBe('small')
		} finally {
			window.matchMedia = original
		}
	})
})
