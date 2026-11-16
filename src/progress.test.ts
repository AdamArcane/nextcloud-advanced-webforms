/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormField } from './types.ts'

import { describe, expect, it } from 'vitest'
import { PROGRESS_TTL_MS, packProgress, restoreProgress } from './progress.ts'

const fields: Record<string, FormField> = {
	f_name: { type: 'text', label: 'Name' },
	f_plan: { type: 'select', label: 'Plan', options: [{ value: 'basic', label: 'Basic' }, { value: 'pro', label: 'Pro' }] },
	f_tags: { type: 'checkboxes', label: 'Tags', options: [{ value: 'red', label: 'Red' }, { value: 'blue', label: 'Blue' }] },
	f_agree: { type: 'consent', label: 'Agree' },
	f_file: { type: 'file', label: 'File' },
	utm_source: { type: 'hidden', label: 'Source' },
	f_note: { type: 'paragraph', text: 'Hello' },
}

const NOW = 1_800_000_000_000

function packed(values: Record<string, unknown>, page = 0): string {
	return packProgress(fields, values, page, 4, NOW) as string
}

describe('packProgress', () => {
	it('keeps answers, and leaves out uploads, hidden fields and empty answers', () => {
		const record = JSON.parse(packed({ f_name: 'Ada', f_plan: '', f_tags: [], f_agree: false, f_file: ['up1'], utm_source: 'partner' }, 2))
		expect(record).toEqual({ format: 1, versionId: 4, savedAt: NOW, page: 2, values: { f_name: 'Ada' } })
	})

	it('gives up on a record that is too large', () => {
		const many: Record<string, FormField> = {}
		const values: Record<string, unknown> = {}
		for (let i = 0; i < 12; i++) {
			many['f_long' + i] = { type: 'textarea', label: 'Long' }
			values['f_long' + i] = 'x'.repeat(20000)
		}
		expect(packProgress(many, values, 0, 4, NOW)).toBeNull()
	})
})

describe('restoreProgress', () => {
	it('gives back what was kept', () => {
		const raw = packed({ f_name: 'Ada', f_plan: 'pro', f_tags: ['blue'], f_agree: true }, 1)
		expect(restoreProgress(raw, fields, NOW + 1000)).toEqual({
			values: { f_name: 'Ada', f_plan: 'pro', f_tags: ['blue'], f_agree: true },
			page: 1,
			savedAt: NOW,
			dropped: false,
		})
	})

	it('leaves out answers the form no longer takes and says so', () => {
		const raw = JSON.stringify({
			format: 1,
			versionId: 3,
			savedAt: NOW,
			page: 0,
			values: { f_name: 'Ada', f_plan: 'enterprise', f_tags: ['blue', 'green'], f_gone: 'x', f_file: ['up1'], utm_source: 'x', f_agree: 'yes' },
		})
		expect(restoreProgress(raw, fields, NOW)).toEqual({ values: { f_name: 'Ada' }, page: 0, savedAt: NOW, dropped: true })
	})

	it('has nothing for a missing, broken, expired or empty record', () => {
		expect(restoreProgress(null, fields, NOW)).toBeNull()
		expect(restoreProgress('{not json', fields, NOW)).toBeNull()
		expect(restoreProgress('[]', fields, NOW)).toBeNull()
		expect(restoreProgress(JSON.stringify({ format: 2, savedAt: NOW, page: 0, values: { f_name: 'Ada' } }), fields, NOW)).toBeNull()
		expect(restoreProgress(packed({ f_name: 'Ada' }), fields, NOW + PROGRESS_TTL_MS + 1)).toBeNull()
		expect(restoreProgress(packed({ f_name: 'Ada' }), fields, NOW - 1)).toBeNull()
		expect(restoreProgress(packed({}), fields, NOW)).toBeNull()
		expect(restoreProgress(JSON.stringify({ format: 1, savedAt: NOW, page: 0, values: { f_gone: 'x' } }), fields, NOW)).toBeNull()
	})

	it('does not trust the page number or odd field ids', () => {
		const raw = JSON.stringify({ format: 1, savedAt: NOW, page: -3, values: { f_name: 'Ada', constructor: 'x', __proto__: 'y' } })
		const restored = restoreProgress(raw, fields, NOW)
		expect(restored?.page).toBe(0)
		expect(restored?.values).toEqual({ f_name: 'Ada' })
	})
})
