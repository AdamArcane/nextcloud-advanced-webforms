// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { CoreImportOutcome } from '../api/forms.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchCoreForms, importCoreForm } from '../api/forms.ts'
import { ApiError } from '../api/http.ts'
import ImportDialog from './ImportDialog.vue'

vi.mock('../api/forms.ts', () => ({
	fetchCoreForms: vi.fn(),
	importCoreForm: vi.fn(),
}))

const forms = [
	{ id: 4, title: 'Workshop signup', responses: 12 },
	{ id: 9, title: 'Feedback', responses: 0 },
]

function outcome(over: Partial<CoreImportOutcome> = {}): CoreImportOutcome {
	return {
		coreId: 4,
		title: 'Workshop signup',
		formId: 31,
		fields: 6,
		responsesFound: 12,
		responsesImported: 0,
		errors: [],
		skipped: [],
		...over,
	}
}

async function mountDialog() {
	const wrapper = mount(ImportDialog, {
		props: { open: true },
		global: { stubs: { teleport: true } },
	})
	await flushPromises()
	return wrapper
}

const button = (wrapper: Awaited<ReturnType<typeof mountDialog>>, text: string) => wrapper.findAll('button').find((el) => el.text().includes(text))!

async function openCore(wrapper: Awaited<ReturnType<typeof mountDialog>>) {
	await button(wrapper, 'From Nextcloud Forms').trigger('click')
	await flushPromises()
}

async function tick(wrapper: Awaited<ReturnType<typeof mountDialog>>, title: string) {
	const row = wrapper.findAll('.checkbox-radio-switch').find((el) => el.text().includes(title))
	expect(row, title).toBeDefined()
	await row!.find('input').setValue(true)
}

beforeEach(() => {
	vi.mocked(fetchCoreForms).mockReset()
	vi.mocked(importCoreForm).mockReset()
	vi.mocked(fetchCoreForms).mockResolvedValue({ available: true, forms })
})

describe('import dialog', () => {
	it('lists where a form can come from', async () => {
		const wrapper = await mountDialog()

		expect(button(wrapper, 'From a file')).toBeDefined()
		const core = button(wrapper, 'From Nextcloud Forms')
		expect(core.attributes('disabled')).toBeUndefined()
		expect(core.text()).toContain('2 forms of yours')
	})

	it('hands the file option back to the screen and closes', async () => {
		const wrapper = await mountDialog()

		await button(wrapper, 'From a file').trigger('click')

		expect(wrapper.emitted('pick-file')).toHaveLength(1)
		expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
	})

	it('turns the core Forms option off, and says why, when there is nothing to import', async () => {
		vi.mocked(fetchCoreForms).mockResolvedValue({ available: false, forms: [] })
		const wrapper = await mountDialog()

		const core = button(wrapper, 'From Nextcloud Forms')
		expect(core.attributes('disabled')).toBeDefined()
		expect(core.text()).toContain('no forms in the Forms app')
	})

	it('says so when it could not look, without offering the option', async () => {
		vi.mocked(fetchCoreForms).mockRejectedValue(new ApiError(500, 'failed'))
		const wrapper = await mountDialog()

		const core = button(wrapper, 'From Nextcloud Forms')
		expect(core.attributes('disabled')).toBeDefined()
		expect(core.text()).toContain('Could not look for your forms')
	})

	it('previews without creating anything, and shows what is left out in words', async () => {
		vi.mocked(importCoreForm).mockResolvedValue(outcome({
			formId: null,
			skipped: [
				{ scope: 'question', ref: '7', label: 'Rank these', code: 'ranking_unsupported', detail: 'x', count: 1 },
				{ scope: 'answer', ref: '8', label: 'Pick', code: 'answer_not_an_option', detail: 'x', count: 3 },
				{ scope: 'form', ref: 'access', label: '', code: 'access_not_carried', detail: 'x', count: 1 },
			],
		}))
		const wrapper = await mountDialog()
		await openCore(wrapper)
		await tick(wrapper, 'Workshop signup')

		await button(wrapper, 'Preview').trigger('click')
		await flushPromises()

		expect(importCoreForm).toHaveBeenCalledWith(4, false, true)
		expect(wrapper.emitted('imported')).toBeUndefined()
		const text = wrapper.text()
		expect(text).toContain('Would be a draft with 6 fields')
		expect(text).toContain('Ranking has no equivalent, so “Rank these” and its answers were left out.')
		expect(text).toContain('3 answers to “Pick” were not imported.')
		expect(text).toContain('Who can open the form')
	})

	it('imports each ticked form on its own, with the responses switch, and reports the new drafts', async () => {
		vi.mocked(importCoreForm)
			.mockResolvedValueOnce(outcome({ responsesImported: 12 }))
			.mockResolvedValueOnce(outcome({ coreId: 9, title: 'Feedback', formId: 32, fields: 2, responsesFound: 0 }))
		const wrapper = await mountDialog()
		await openCore(wrapper)
		await wrapper.findAll('.checkbox-radio-switch').find((el) => el.text().includes('All forms'))!.find('input').setValue(true)
		await wrapper.findAll('.checkbox-radio-switch').find((el) => el.text().includes('Also import the responses'))!.find('input').setValue(true)

		await button(wrapper, 'Import').trigger('click')
		await flushPromises()

		expect(importCoreForm).toHaveBeenNthCalledWith(1, 4, true, false)
		expect(importCoreForm).toHaveBeenNthCalledWith(2, 9, true, false)
		expect(wrapper.emitted('imported')?.[0]).toEqual([[31, 32]])
		expect(wrapper.text()).toContain('Imported as a closed form with 6 fields and 12 of 12 responses')
	})

	it('keeps going when one form fails and says which', async () => {
		vi.mocked(importCoreForm)
			.mockRejectedValueOnce(new ApiError(422, 'validation', [{ code: 'create_not_allowed' }]))
			.mockResolvedValueOnce(outcome({ coreId: 9, title: 'Feedback', formId: 32, fields: 2, responsesFound: 0 }))
		const wrapper = await mountDialog()
		await openCore(wrapper)
		await tick(wrapper, 'Workshop signup')
		await tick(wrapper, 'Feedback')

		await button(wrapper, 'Import').trigger('click')
		await flushPromises()

		expect(importCoreForm).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('imported')?.[0]).toEqual([[32]])
		expect(wrapper.text()).toContain('not allowed to create forms')
	})

	it('will not import with nothing ticked', async () => {
		const wrapper = await mountDialog()
		await openCore(wrapper)

		expect(button(wrapper, 'Import').attributes('disabled')).toBeDefined()
		expect(button(wrapper, 'Preview').attributes('disabled')).toBeDefined()
	})
})
