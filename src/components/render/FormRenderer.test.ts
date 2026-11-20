// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PublicForm } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/public.ts'
import FormRenderer from './FormRenderer.vue'

const submitMock = vi.fn()
vi.mock('../../api/public.ts', async (importOriginal) => ({
	...(await importOriginal<typeof import('../../api/public.ts')>()),
	submitPublicForm: (...args: unknown[]) => submitMock(...args),
}))

const form: PublicForm = {
	token: 'tok',
	versionId: 4,
	fillToken: 'fill.token',
	definition: {
		schemaVersion: 1,
		title: 'Inquiry',
		settings: { submitLabel: 'Send it' },
		fields: {
			f_name: { type: 'text', label: 'Name', required: true },
			f_kind: { type: 'select', label: 'Kind', required: true, options: [{ value: 'new', label: 'New' }, { value: 'support', label: 'Support' }] },
			f_url: { type: 'text', label: 'Website' },
			f_tags: { type: 'checkboxes', label: 'Tags', options: [{ value: 'aaa', label: 'A' }, { value: 'bbb', label: 'B' }] },
			f_hidden: { type: 'text', label: 'Hidden label', hideLabel: true },
			f_note: { type: 'paragraph', text: 'Hello <img src=x onerror=alert(1)> **bold**' },
		},
		pages: [{
			id: 'p_1',
			title: null,
			rows: [
				{ id: 'r_1', cells: [{ id: 'c_1', span: 6, field: 'f_name' }, { id: 'c_2', span: 6, field: 'f_kind' }] },
				{ id: 'r_2', cells: [{ id: 'c_3', span: 12, field: 'f_url' }] },
				{ id: 'r_3', cells: [{ id: 'c_4', span: 12, field: 'f_tags' }] },
				{ id: 'r_4', cells: [{ id: 'c_5', span: 12, field: 'f_hidden' }] },
				{ id: 'r_5', cells: [{ id: 'c_6', span: 12, field: 'f_note' }] },
			],
		}],
		rules: [{
			id: 'rule_1',
			effect: 'show',
			targets: ['f_url'],
			when: { all: [{ field: 'f_kind', op: 'eq', value: 'support' }] },
		}],
	},
}

function render() {
	return mount(FormRenderer, { props: { form }, attachTo: document.body })
}

beforeEach(() => {
	submitMock.mockReset()
	document.body.innerHTML = ''
})

describe('FormRenderer', () => {
	it('shows only the fields the rules allow', async () => {
		const wrapper = render()
		expect(wrapper.find('#af-f_url').exists()).toBe(false)

		await wrapper.find('#af-f_kind').setValue('support')
		expect(wrapper.find('#af-f_url').exists()).toBe(true)

		await wrapper.find('#af-f_kind').setValue('new')
		expect(wrapper.find('#af-f_url').exists()).toBe(false)
	})

	it('uses the configured submit label', () => {
		expect(render().find('button[type=submit]').text()).toBe('Send it')
	})

	it('blocks submit and reports missing required fields', async () => {
		const wrapper = render()
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(submitMock).not.toHaveBeenCalled()
		const alerts = wrapper.findAll('.af-error').map((node) => node.text())
		expect(alerts).toHaveLength(2)
		expect(wrapper.find('#af-f_name').attributes('aria-invalid')).toBe('true')
		expect(wrapper.find('#af-f_name').attributes('aria-required')).toBe('true')
	})

	it('submits visible values only and never hidden ones', async () => {
		submitMock.mockResolvedValue({ message: null, redirect: null })
		const wrapper = render()

		await wrapper.find('#af-f_kind').setValue('support')
		await wrapper.find('#af-f_url').setValue('https://example.com')
		await wrapper.find('#af-f_kind').setValue('new') // hides f_url again
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('input[value=bbb]').setValue(true)
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(submitMock).toHaveBeenCalledTimes(1)
		const [token, body] = submitMock.mock.calls[0]
		expect(token).toBe('tok')
		expect(body.versionId).toBe(4)
		expect(body.fillToken).toBe('fill.token')
		expect(body.hp).toBe('')
		expect(body.values.f_name).toBe('Ada')
		expect(body.values.f_kind).toBe('new')
		expect(body.values.f_tags).toEqual(['bbb'])
		expect('f_url' in body.values).toBe(false)
		expect('f_note' in body.values).toBe(false)
	})

	it('sends the honeypot value when something fills it', async () => {
		submitMock.mockResolvedValue({ message: null, redirect: null })
		const wrapper = render()
		await wrapper.find('#af-f_name').setValue('Bot')
		await wrapper.find('#af-f_kind').setValue('new')
		await wrapper.find('input[name=website]').setValue('spam')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(submitMock.mock.calls[0][1].hp).toBe('spam')
	})

	it('shows the thank-you message after success', async () => {
		submitMock.mockResolvedValue({ message: 'Got it, thanks.', redirect: null })
		const wrapper = render()
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('#af-f_kind').setValue('new')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(wrapper.text()).toContain('Got it, thanks.')
		expect(wrapper.find('form').exists()).toBe(false)
	})

	it('shows server field errors next to the field', async () => {
		submitMock.mockRejectedValue(new ApiError(422, 'validation', [{ field: 'f_name', code: 'too_long' }]))
		const wrapper = render()
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('#af-f_kind').setValue('new')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[data-field=f_name] .af-error').text()).toContain('too long')
		expect(wrapper.find('form').exists()).toBe(true)
	})

	it('asks for a fresh token when the server says it expired', async () => {
		submitMock.mockRejectedValue(new ApiError(422, 'validation', [{ code: 'token_expired' }]))
		const wrapper = render()
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('#af-f_kind').setValue('new')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(wrapper.emitted('refreshToken')).toHaveLength(1)
	})

	it('shows a rate limit message', async () => {
		submitMock.mockRejectedValue(new ApiError(429, 'failed'))
		const wrapper = render()
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('#af-f_kind').setValue('new')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(wrapper.text()).toContain('Too many attempts')
	})

	it('keeps hidden labels in the DOM for screen readers', () => {
		const label = render().find('label[for=af-f_hidden]')
		expect(label.exists()).toBe(true)
		expect(label.classes()).toContain('af-sr-only')
	})

	it('renders paragraph markdown without creating raw HTML elements', () => {
		const wrapper = render()
		expect(wrapper.find('.af-markdown strong').text()).toBe('bold')
		expect(wrapper.find('.af-markdown img').exists()).toBe(false)
		expect(wrapper.find('.af-markdown').text()).toContain('<img src=x onerror=alert(1)>')
	})

	it('does not submit in preview mode', async () => {
		const wrapper = mount(FormRenderer, { props: { form, preview: true } })
		await wrapper.find('#af-f_name').setValue('Ada')
		await wrapper.find('form').trigger('submit')
		expect(submitMock).not.toHaveBeenCalled()
	})

})

describe('FormRenderer with a rating, a scale and a consent', () => {
	const rated: PublicForm = {
		...form,
		definition: {
			...form.definition,
			fields: {
				f_stars: { type: 'rating', label: 'Stars', required: true },
				f_nps: { type: 'scale', label: 'Recommend', min: 0, max: 10, minLabel: 'Not likely', maxLabel: 'Very likely' },
				f_terms: { type: 'consent', label: 'Terms', required: true, text: 'I agree to the [terms](https://example.com/terms).' },
				f_when: { type: 'datetime', label: 'When' },
			},
			rules: [],
			pages: [{ id: 'p_1', title: null, rows: ['f_stars', 'f_nps', 'f_terms', 'f_when'].map((field, index) => ({ id: 'r_' + index, cells: [{ id: 'c_' + index, span: 12, field }] })) }],
		},
	}

	it('draws them and sends what was picked', async () => {
		submitMock.mockResolvedValue({ message: null, redirect: null })
		const wrapper = mount(FormRenderer, { props: { form: rated }, attachTo: document.body })
		expect(wrapper.findAll('[data-field=f_stars] input[type=radio]')).toHaveLength(5)
		expect(wrapper.findAll('[data-field=f_nps] input[type=radio]')).toHaveLength(11)
		expect(wrapper.find('[data-field=f_nps]').text()).toContain('Very likely')
		expect(wrapper.find('[data-field=f_terms] a').attributes('href')).toBe('https://example.com/terms')
		expect(wrapper.find('#af-f_when').attributes('type')).toBe('datetime-local')

		// Both required answers are missing.
		await wrapper.find('form').trigger('submit')
		expect(submitMock).not.toHaveBeenCalled()
		expect(wrapper.findAll('.af-error')).toHaveLength(2)

		await wrapper.findAll('[data-field=f_stars] input')[3].trigger('click')
		expect(wrapper.findAll('[data-field=f_stars] .af-rating__star--on')).toHaveLength(4)
		await wrapper.findAll('[data-field=f_nps] input')[0].trigger('click')
		await wrapper.find('#af-f_terms').setValue(true)
		await wrapper.find('#af-f_when').setValue('2026-05-01T09:30')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(submitMock.mock.calls[0][1].values).toEqual({ f_stars: '4', f_nps: '0', f_terms: true, f_when: '2026-05-01T09:30' })
	})

	it('takes a pick back when it is picked again', async () => {
		const wrapper = mount(FormRenderer, { props: { form: rated, preview: true }, attachTo: document.body })
		const third = wrapper.findAll('[data-field=f_stars] input')[2]
		await third.trigger('click')
		expect(wrapper.findAll('.af-rating__star--on')).toHaveLength(3)
		await third.trigger('click')
		expect(wrapper.findAll('.af-rating__star--on')).toHaveLength(0)
	})
})

describe('FormRenderer with a yes or no question', () => {
	const asked: PublicForm = {
		...form,
		definition: {
			...form.definition,
			fields: {
				f_ok: { type: 'radio', boolean: true, label: 'Ok?', required: true, options: [{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }] },
			},
			rules: [],
			pages: [{ id: 'p_1', title: null, rows: [{ id: 'r_1', cells: [{ id: 'c_1', span: 12, field: 'f_ok' }] }] }],
		},
	}

	it('needs an answer, and sends false for No', async () => {
		submitMock.mockResolvedValue({ message: null, redirect: null })
		const wrapper = mount(FormRenderer, { props: { form: asked }, attachTo: document.body })
		await wrapper.find('form').trigger('submit')
		expect(submitMock).not.toHaveBeenCalled()
		await wrapper.findAll('[data-field=f_ok] input[type=radio]')[1].setValue(true)
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(submitMock.mock.calls[0][1].values).toEqual({ f_ok: false })
	})
})

describe('FormRenderer with image blocks', () => {
	const pictured = (preview: boolean) => mount(FormRenderer, {
		props: {
			preview,
			form: {
				...form,
				definition: {
					...form.definition,
					fields: {
						f_remote: { type: 'image', src: 'https://example.com/logo.png', alt: 'Logo', width: 50 },
						f_local: { type: 'image', src: preview ? 'Pictures/team photo.png' : '@files' },
						f_bad: { type: 'image', src: 'javascript:alert(1)' },
					},
					rules: [],
					pages: [{ id: 'p_1', title: null, rows: ['f_remote', 'f_local', 'f_bad'].map((field, index) => ({ id: 'r_' + index, cells: [{ id: 'c_' + index, span: 12, field }] })) }],
				},
			},
		},
		attachTo: document.body,
	})

	it('loads a remote picture from its address and one in Files through the form', () => {
		const images = pictured(false).findAll('img')
		expect(images).toHaveLength(2)
		expect(images[0].attributes('src')).toBe('https://example.com/logo.png')
		expect(images[0].attributes('alt')).toBe('Logo')
		expect(images[0].attributes('style')).toContain('50%')
		expect(images[1].attributes('src')).toContain('/public/tok/image/f_local?v=4')
	})

	it('shows the owner their own file in the preview', () => {
		const images = pictured(true).findAll('img')
		expect(images[1].attributes('src')).toContain('/image?path=Pictures%2Fteam%20photo.png')
	})
})

describe('FormRenderer with several pages', () => {
	const paged: PublicForm = {
		...form,
		definition: {
			...form.definition,
			fields: {
				f_name: { type: 'text', label: 'Name', required: true },
				f_mail: { type: 'text', label: 'Mail' },
			},
			rules: [],
			pages: [
				{ id: 'p_1', title: 'About you', rows: [{ id: 'r_1', cells: [{ id: 'c_1', span: 12, field: 'f_name' }] }] },
				{ id: 'p_2', title: null, rows: [{ id: 'r_2', cells: [{ id: 'c_2', span: 12, field: 'f_mail' }] }] },
			],
		},
	}

	it('steps through pages, checking required fields before moving on', async () => {
		submitMock.mockResolvedValue({ message: null, redirect: null })
		const wrapper = mount(FormRenderer, { props: { form: paged }, attachTo: document.body })
		expect(wrapper.find('#af-f_mail').exists()).toBe(false)
		expect(wrapper.find('button[type=submit]').text()).toBe('Next')

		await wrapper.find('form').trigger('submit')
		expect(wrapper.find('#af-f_mail').exists()).toBe(false)

		await wrapper.find('#af-f_name').setValue('Ann')
		await wrapper.find('form').trigger('submit')
		expect(wrapper.find('#af-f_mail').exists()).toBe(true)
		expect(wrapper.find('button[type=submit]').text()).toBe('Send it')
		expect(submitMock).not.toHaveBeenCalled()

		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(submitMock).toHaveBeenCalledTimes(1)
		expect(submitMock.mock.calls[0][1].values).toEqual({ f_name: 'Ann', f_mail: '' })
	})

	it('shows a progress bar with the step number by default', async () => {
		const wrapper = mount(FormRenderer, { props: { form: paged, preview: true }, attachTo: document.body })
		expect(wrapper.find('.af-progress__label').text()).toBe('Step 1 of 2')
		expect(wrapper.find('[role=progressbar]').attributes('aria-valuenow')).toBe('50')
		await wrapper.find('form').trigger('submit')
		expect(wrapper.find('.af-progress__label').text()).toBe('Step 2 of 2')
		expect(wrapper.find('[role=progressbar]').attributes('aria-valuenow')).toBe('100')
		expect(document.activeElement).toBe(wrapper.find('.af-progress__label').element)
	})

	it('follows the design: steps, page name, percent or nothing', async () => {
		const withDesign = (design: NonNullable<PublicForm['definition']['settings']>['design']) => mount(FormRenderer, {
			props: { form: { ...paged, definition: { ...paged.definition, settings: { design } } }, preview: true },
			attachTo: document.body,
		})
		const named = withDesign({ progress: 'steps', progressLabel: 'title' })
		expect(named.find('[role=progressbar]').exists()).toBe(false)
		expect(named.findAll('.af-progress__step')).toHaveLength(2)
		expect(named.find('[aria-current=step]').text()).toBe('1')
		expect(named.find('.af-progress__label').text()).toBe('About you')
		// The second page has no name, so it falls back to its step number.
		await named.find('form').trigger('submit')
		expect(named.find('.af-progress__label').text()).toBe('Step 2 of 2')
		expect(named.find('[aria-current=step]').text()).toBe('2')

		expect(withDesign({ progressColor: '#00aa55' }).find('.af-progress').attributes('style')).toContain('#00aa55')
		expect(withDesign({ progressLabel: 'percent' }).find('.af-progress__label').text()).toBe('50%')
		const bare = withDesign({ progressLabel: 'none' })
		expect(bare.find('.af-progress__label').exists()).toBe(false)
		expect(bare.find('[role=progressbar]').exists()).toBe(true)
		expect(withDesign({ progress: 'none', progressLabel: 'none' }).find('.af-progress').exists()).toBe(false)
	})

	it('shows no progress on a form with one page', () => {
		expect(render().find('.af-progress').exists()).toBe(false)
	})

	it('lets the preview page through but never submits', async () => {
		submitMock.mockReset()
		const wrapper = mount(FormRenderer, { props: { form: paged, preview: true }, attachTo: document.body })
		// f_name is required and empty, but the preview does not check.
		await wrapper.find('form').trigger('submit')
		expect(wrapper.find('#af-f_mail').exists()).toBe(true)
		expect(wrapper.text()).not.toContain('required')
		await wrapper.find('form').trigger('submit')
		expect(submitMock).not.toHaveBeenCalled()
	})

	it('checks required fields in the preview when validation is switched on', async () => {
		const wrapper = mount(FormRenderer, { props: { form: paged, preview: true, validate: true }, attachTo: document.body })
		await wrapper.find('form').trigger('submit')
		expect(wrapper.find('#af-f_mail').exists()).toBe(false)
		await wrapper.find('#af-f_name').setValue('Ann')
		await wrapper.find('form').trigger('submit')
		expect(wrapper.find('#af-f_mail').exists()).toBe(true)
	})
})
