// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OwnerForm } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/http.ts'
import SharePanel from './SharePanel.vue'

const setFormShare = vi.hoisted(() => vi.fn())

vi.mock('../../api/forms.ts', () => ({
	setFormShare,
	publicFormUrl: (token: string) => 'https://cloud.example.com/apps/webforms/f/' + token,
	embedFormUrl: (token: string) => 'https://cloud.example.com/apps/webforms/f/' + token + '/embed',
}))

function form(patch: Partial<OwnerForm> = {}): OwnerForm {
	return {
		id: 7,
		title: 'Contact us',
		description: null,
		status: 1,
		publicToken: 'tok123',
		slug: null,
		embed: false,
		embedOrigins: [],
		latestVersionId: 3,
		createdAt: 1,
		updatedAt: 2,
		...patch,
	}
}

function button(wrapper: ReturnType<typeof mount>, text: string) {
	return wrapper.findAll('button').find((node) => node.text() === text)
}

beforeEach(() => {
	setFormShare.mockReset()
})

describe('sharing a form', () => {
	it('shows the token link and a QR code for a published form', () => {
		const wrapper = mount(SharePanel, { props: { form: form() } })
		expect((wrapper.find('input[readonly]').element as HTMLInputElement).value).toBe('https://cloud.example.com/apps/webforms/f/tok123')
		expect(wrapper.find('img.af-share__qr').attributes('src')).toContain('data:image/svg+xml')
	})

	it('hands out the custom address when there is one', () => {
		const wrapper = mount(SharePanel, { props: { form: form({ slug: 'contact' }) } })
		expect((wrapper.find('input[readonly]').element as HTMLInputElement).value).toBe('https://cloud.example.com/apps/webforms/f/contact')
	})

	it('has no link, QR code or embed code before the form is published', () => {
		const wrapper = mount(SharePanel, { props: { form: form({ publicToken: null, status: 0, embed: true }) } })
		expect(wrapper.text()).toContain('Publish the form to get a link you can share.')
		expect(wrapper.find('img.af-share__qr').exists()).toBe(false)
		expect(wrapper.text()).toContain('Publish the form to get the code.')
	})

	it('saves a custom address and keeps the other options', async () => {
		setFormShare.mockResolvedValue(form({ slug: 'contact', embed: true }))
		const wrapper = mount(SharePanel, { props: { form: form({ embed: true, embedOrigins: ['https://a.example'] }) } })
		await wrapper.find('input:not([readonly])').setValue('contact')
		await button(wrapper, 'Save address')!.trigger('click')
		await flushPromises()
		expect(setFormShare).toHaveBeenCalledWith(7, { slug: 'contact', embed: true, embedOrigins: ['https://a.example'] })
		expect(wrapper.emitted('changed')![0][0]).toMatchObject({ slug: 'contact' })
	})

	it('does not send an address the server would refuse', async () => {
		const wrapper = mount(SharePanel, { props: { form: form() } })
		await wrapper.find('input:not([readonly])').setValue('No Good')
		await button(wrapper, 'Save address')!.trigger('click')
		await flushPromises()
		expect(setFormShare).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Use 3 to 64 lowercase letters')
	})

	it('says when another form has the address', async () => {
		setFormShare.mockRejectedValue(new ApiError(422, 'validation', [{ code: 'slug_taken', path: 'slug' }] as never))
		const wrapper = mount(SharePanel, { props: { form: form() } })
		await wrapper.find('input:not([readonly])').setValue('contact')
		await button(wrapper, 'Save address')!.trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('Another form already uses this address.')
		expect(wrapper.emitted('changed')).toBeUndefined()
	})

	it('removes the address when the field is emptied', async () => {
		setFormShare.mockResolvedValue(form())
		const wrapper = mount(SharePanel, { props: { form: form({ slug: 'contact' }) } })
		await wrapper.find('input:not([readonly])').setValue('')
		await button(wrapper, 'Remove address')!.trigger('click')
		await flushPromises()
		expect(setFormShare).toHaveBeenCalledWith(7, { slug: null, embed: false, embedOrigins: [] })
	})

	it('switches embedding on and only then shows the code', async () => {
		setFormShare.mockResolvedValue(form({ embed: true }))
		const wrapper = mount(SharePanel, { props: { form: form() } })
		expect(wrapper.text()).not.toContain('Embed code')
		await wrapper.find('input[type="checkbox"]').setValue(true)
		await flushPromises()
		expect(setFormShare).toHaveBeenCalledWith(7, { slug: null, embed: true, embedOrigins: [] })

		await wrapper.setProps({ form: form({ embed: true }) })
		// One box: HTML only until the switch for fitting the height is on.
		const code = () => wrapper.findAll('textarea[readonly]').map((node) => (node.element as HTMLTextAreaElement).value)
		expect(code()).toHaveLength(1)
		expect(code()[0]).toContain('<iframe src="https://cloud.example.com/apps/webforms/f/tok123/embed" title="Contact us"')
		expect(code()[0]).not.toContain('<script')
		await wrapper.findAll('input[type="checkbox"]')[1].setValue(true)
		expect(code()).toHaveLength(1)
		expect(code()[0]).toContain('<iframe id="webform-7"')
		expect(code()[0]).toContain('webforms:height')
		expect(wrapper.text()).toContain('any website may embed this form')
	})

	it('saves the allowed websites', async () => {
		setFormShare.mockResolvedValue(form({ embed: true, embedOrigins: ['https://a.example', 'https://b.example'] }))
		const wrapper = mount(SharePanel, { props: { form: form({ embed: true }) } })
		await wrapper.find('textarea:not([readonly])').setValue('https://a.example\nhttps://b.example/')
		await button(wrapper, 'Save websites')!.trigger('click')
		await flushPromises()
		expect(setFormShare).toHaveBeenCalledWith(7, { slug: null, embed: true, embedOrigins: ['https://a.example', 'https://b.example'] })
	})

	it('warns that a form that requires login cannot be filled in when embedded', () => {
		const wrapper = mount(SharePanel, { props: { form: form({ embed: true }), requireLogin: true } })
		expect(wrapper.text()).toContain('An embedded form is always anonymous')
	})
})
