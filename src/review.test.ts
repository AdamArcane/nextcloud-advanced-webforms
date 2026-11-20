/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LayoutPage } from './layout/visibleLayout.ts'
import type { FormField } from './types.ts'

import { describe, expect, it } from 'vitest'
import { buildReview, isUnanswered } from './review.ts'

const fields: Record<string, FormField> = {
	f_name: { type: 'text', label: 'Name', required: true },
	f_plan: { type: 'select', label: 'Plan', options: [{ value: 'basic', label: 'Basic plan' }, { value: 'pro', label: 'Pro plan' }] },
	f_tags: { type: 'checkboxes', label: 'Tags', options: [{ value: 'red', label: 'Red' }, { value: 'blue', label: 'Blue' }] },
	f_agree: { type: 'consent', label: 'Terms' },
	f_file: { type: 'file', label: 'Attachment' },
	f_note: { type: 'textarea' },
	utm: { type: 'hidden', label: 'Source' },
	f_intro: { type: 'paragraph', text: 'Hello' },
}

const cell = (field: string) => ({ id: 'c_' + field, span: 12, field })
const pages: LayoutPage[] = [
	{ id: 'p_1', title: 'About you', rows: [{ id: 'r_1', cells: [cell('f_intro'), cell('f_name'), cell('f_plan')] }, { id: 'r_2', cells: [cell('utm')] }] },
	{ id: 'p_2', title: null, rows: [{ id: 'r_3', cells: [cell('f_tags'), cell('f_agree'), cell('f_file'), cell('f_note')] }] },
]

describe('isUnanswered', () => {
	it('reads each kind of answer', () => {
		expect(isUnanswered(fields.f_name, '  ')).toBe(true)
		expect(isUnanswered(fields.f_name, 'Ada')).toBe(false)
		expect(isUnanswered(fields.f_tags, [])).toBe(true)
		expect(isUnanswered(fields.f_agree, false)).toBe(true)
		expect(isUnanswered(fields.f_agree, true)).toBe(false)
		// A yes or no question: "No" is an answer, nothing is not.
		const yesNo = { type: 'radio', boolean: true, label: 'Ok?', options: [] } as FormField
		expect(isUnanswered(yesNo, false)).toBe(false)
		expect(isUnanswered(yesNo, true)).toBe(false)
		expect(isUnanswered(yesNo, null)).toBe(true)
	})
})

describe('buildReview', () => {
	it('lists answers as people read them, grouped by page, and counts skipped optional ones', () => {
		const review = buildReview(
			pages,
			fields,
			{ f_name: 'Ada', f_plan: 'pro', f_tags: ['blue', 'red'], f_agree: true, f_file: ['up1'], f_note: '', utm: 'partner' },
			new Set(['f_name']),
			(id) => (id === 'f_file' ? ['cv.pdf', 'letter.pdf'] : []),
		)
		expect(review).toEqual({
			groups: [
				{ pageIndex: 0, title: 'About you', items: [{ id: 'f_name', label: 'Name', text: 'Ada', missing: false }, { id: 'f_plan', label: 'Plan', text: 'Pro plan', missing: false }] },
				{ pageIndex: 1, title: null, items: [
					{ id: 'f_tags', label: 'Tags', text: 'Blue, Red', missing: false },
					{ id: 'f_agree', label: 'Terms', text: 'Yes', missing: false },
					{ id: 'f_file', label: 'Attachment', text: 'cv.pdf, letter.pdf', missing: false },
				] },
			],
			skipped: 1,
		})
	})

	it('flags required fields without an answer and only counts the optional ones', () => {
		const review = buildReview(pages, fields, { f_name: '', f_plan: '', f_tags: [], f_agree: false, f_file: [], f_note: '' }, new Set(['f_name', 'f_agree']), () => [])
		expect(review.groups).toEqual([
			{ pageIndex: 0, title: 'About you', items: [{ id: 'f_name', label: 'Name', text: '', missing: true }] },
			{ pageIndex: 1, title: null, items: [{ id: 'f_agree', label: 'Terms', text: '', missing: true }] },
		])
		expect(review.skipped).toBe(4)
	})

	it('names a field by its id when it has no label, and has no groups when nothing is listed', () => {
		const review = buildReview(pages, fields, { f_note: 'Hello' }, new Set(), () => [])
		expect(review.groups[0].items[0].label).toBe('f_note')
		expect(buildReview([], fields, {}, new Set(), () => [])).toEqual({ groups: [], skipped: 0 })
	})
})
