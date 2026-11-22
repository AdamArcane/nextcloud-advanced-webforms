// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { CoreImportSkipped } from './api/forms.ts'

import { describe, expect, it } from 'vitest'
import { describeSkipped } from './importReport.ts'

const item = (over: Partial<CoreImportSkipped>): CoreImportSkipped => ({ scope: 'question', ref: '5', label: '', code: 'x', detail: 'Server sentence.', count: 1, ...over })

describe('import report', () => {
	it('names a question by its text, or by its number when it has none', () => {
		expect(describeSkipped(item({ code: 'no_options', label: 'Pick one' }))).toBe('“Pick one” has no options and was left out.')
		expect(describeSkipped(item({ code: 'no_options' }))).toBe('question 5 has no options and was left out.')
	})

	it('counts answers, one and many', () => {
		expect(describeSkipped(item({ scope: 'answer', code: 'answer_invalid', label: 'Age', count: 1 }))).toBe('1 answer to “Age” was not imported.')
		expect(describeSkipped(item({ scope: 'answer', code: 'answer_invalid', label: 'Age', count: 40 }))).toBe('40 answers to “Age” were not imported.')
	})

	it('tells a kept question with files from files that were answered', () => {
		expect(describeSkipped(item({ code: 'files_not_carried', label: 'Photo' }))).toContain('was kept')
		expect(describeSkipped(item({ scope: 'answer', code: 'files_not_carried', label: 'Photo', count: 2 }))).toBe('2 files uploaded to “Photo” stay in the Forms app and were not copied.')
	})

	it('falls back to the server sentence for a code it does not know', () => {
		expect(describeSkipped(item({ code: 'something_new' }))).toBe('Server sentence.')
	})
})
