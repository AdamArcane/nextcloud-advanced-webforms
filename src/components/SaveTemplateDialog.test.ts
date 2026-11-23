// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OwnerForm } from '../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { saveAsTemplate } from '../api/forms.ts'
import { ApiError } from '../api/http.ts'
import SaveTemplateDialog from './SaveTemplateDialog.vue'

vi.mock('../api/forms.ts', () => ({ saveAsTemplate: vi.fn() }))

function form(over: Partial<OwnerForm> = {}): OwnerForm {
	return {
		id: 5,
		title: 'Visitor sign-in',
		description: 'Everyone who comes to the office signs in here.',
		status: 1,
		publicToken: null,
		latestVersionId: null,
		...over,
	} as OwnerForm
}

async function mountDialog(source: OwnerForm | null = form()) {
	const wrapper = mount(SaveTemplateDialog, {
		props: { open: false, form: source },
		global: { stubs: { teleport: true } },
	})
	await wrapper.setProps({ open: true })
	await flushPromises()
	return wrapper
}

const button = (wrapper: Awaited<ReturnType<typeof mountDialog>>, text: string) => wrapper.findAll('button').find((el) => el.text() === text)!
const nameInput = (wrapper: Awaited<ReturnType<typeof mountDialog>>) => wrapper.find('input.input-field__input')
const descriptionInput = (wrapper: Awaited<ReturnType<typeof mountDialog>>) => wrapper.find('textarea')

beforeEach(() => {
	vi.mocked(saveAsTemplate).mockReset()
})

describe('save as template dialog', () => {
	it('names the template after the form', async () => {
		const wrapper = await mountDialog()
		expect((nameInput(wrapper).element as HTMLInputElement).value).toBe('Visitor sign-in template')
	})

	it('keeps the suggested name within the limit for a very long title', async () => {
		const wrapper = await mountDialog(form({ title: 'x'.repeat(255) }))
		const value = (nameInput(wrapper).element as HTMLInputElement).value
		expect(value.length).toBeLessThanOrEqual(255)
		expect(value.endsWith(' template')).toBe(true)
	})

	it('starts with an empty description and a placeholder, whatever the form says', async () => {
		const wrapper = await mountDialog()
		const area = descriptionInput(wrapper)
		expect((area.element as HTMLTextAreaElement).value).toBe('')
		expect(area.attributes('placeholder')).toBe('What is this template for?')
	})

	it('shows the text above the fields on a wide screen and hides it on a phone, with no help under the description', async () => {
		const wide = await mountDialog()
		expect(wide.text()).toContain('Only you can see your templates')
		expect(wide.text()).not.toContain('Shown under the name')
		wide.unmount()

		const original = window.matchMedia
		window.matchMedia = ((query: string) => ({ matches: true, media: query, addEventListener: vi.fn(), removeEventListener: vi.fn() })) as unknown as typeof window.matchMedia
		try {
			const phone = await mountDialog()
			expect(phone.text()).not.toContain('Only you can see your templates')
			expect(phone.text()).toContain('Name')
		} finally {
			window.matchMedia = original
		}
	})

	it('saves what was typed and tells the screen', async () => {
		vi.mocked(saveAsTemplate).mockResolvedValue({ id: 'u:9', name: 'Front desk', description: 'Sign-in sheet.', fieldCount: 3, updatedAt: 1 })
		const wrapper = await mountDialog()
		await nameInput(wrapper).setValue('  Front desk ')
		await descriptionInput(wrapper).setValue('Sign-in sheet.')
		await button(wrapper, 'Save template').trigger('click')
		await flushPromises()
		expect(saveAsTemplate).toHaveBeenCalledWith(5, 'Front desk', 'Sign-in sheet.')
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.emitted('update:open')!.at(-1)).toEqual([false])
	})

	it('can save without a description', async () => {
		vi.mocked(saveAsTemplate).mockResolvedValue({ id: 'u:9', name: 'x', description: '', fieldCount: 1, updatedAt: 1 })
		const wrapper = await mountDialog()
		await button(wrapper, 'Save template').trigger('click')
		await flushPromises()
		expect(saveAsTemplate).toHaveBeenCalledWith(5, 'Visitor sign-in template', '')
	})

	it('needs a name', async () => {
		const wrapper = await mountDialog()
		await nameInput(wrapper).setValue('   ')
		expect(button(wrapper, 'Save template').attributes('disabled')).toBeDefined()
		await button(wrapper, 'Save template').trigger('click')
		expect(saveAsTemplate).not.toHaveBeenCalled()
	})

	it('stays open and says why when the server refuses', async () => {
		vi.mocked(saveAsTemplate).mockRejectedValue(new ApiError(422, 'validation', [{ code: 'template_limit' }]))
		const wrapper = await mountDialog()
		await button(wrapper, 'Save template').trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('template')
		expect(wrapper.emitted('saved')).toBeUndefined()
		expect(button(wrapper, 'Save template').attributes('disabled')).toBeUndefined()
	})

	it('offers fresh defaults when opened again for another form', async () => {
		const wrapper = await mountDialog()
		await nameInput(wrapper).setValue('Something else')
		await wrapper.setProps({ open: false })
		await wrapper.setProps({ form: form({ id: 6, title: 'Event RSVP', description: null }), open: true })
		await flushPromises()
		expect((nameInput(wrapper).element as HTMLInputElement).value).toBe('Event RSVP template')
	})
})
