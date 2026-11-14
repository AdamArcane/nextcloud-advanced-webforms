/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { VersionDefinition } from '../types.ts'

import { describe, expect, it } from 'vitest'
import { canRetry, describeDeliveryError } from './deliveryText.ts'
import { answerFieldOrder, answerLines, displayAnswer, inFuture, previewLines } from './format.ts'

const version: VersionDefinition = {
	fields: {
		f_name: { type: 'text', label: 'Name' },
		f_kind: { type: 'select', label: 'Kind', options: [{ value: 'new', label: 'New website' }] },
		f_tags: { type: 'checkboxes', label: 'Tags', options: [{ value: 'a', label: 'Alpha' }, { value: 'b', label: 'Beta' }] },
		f_ok: { type: 'checkbox', label: 'OK' },
		f_age: { type: 'number', label: 'Age' },
		f_note: { type: 'paragraph', text: 'display only' },
		f_unplaced: { type: 'text', label: 'Unplaced' },
	},
	pages: [{ id: 'p_1', title: null, rows: [
		{ id: 'r_1', cells: [{ id: 'c_1', span: 6, field: 'f_name' }, { id: 'c_2', span: 6, field: 'f_kind' }] },
		{ id: 'r_2', cells: [{ id: 'c_3', span: 12, field: 'f_note' }] },
		{ id: 'r_3', cells: [{ id: 'c_4', span: 12, field: 'f_tags' }, { id: 'c_5', span: 12, field: 'f_ok' }, { id: 'c_6', span: 12, field: 'f_age' }] },
	] }],
}

describe('displayAnswer', () => {
	it('reads answers the way the emails and the CSV do', () => {
		expect(displayAnswer(version.fields.f_name, 'Ada')).toBe('Ada')
		expect(displayAnswer(version.fields.f_kind, 'new')).toBe('New website')
		expect(displayAnswer(version.fields.f_kind, 'gone')).toBe('gone')
		expect(displayAnswer(version.fields.f_tags, ['a', 'b'])).toBe('Alpha, Beta')
		expect(displayAnswer(version.fields.f_ok, true)).toBe('Yes')
		expect(displayAnswer(version.fields.f_ok, false)).toBe('No')
		expect(displayAnswer(version.fields.f_age, 36)).toBe('36')
	})

	it('shows nothing for a missing answer', () => {
		expect(displayAnswer(version.fields.f_name, undefined)).toBe('')
		expect(displayAnswer(version.fields.f_name, null)).toBe('')
		expect(displayAnswer(version.fields.f_tags, [])).toBe('')
	})

	it('shows text as text, never as markup', () => {
		expect(displayAnswer(version.fields.f_name, '<img src=x onerror=alert(1)>')).toBe('<img src=x onerror=alert(1)>')
	})
})

describe('answer order and lines', () => {
	it('lists input fields in layout order, skips display fields, and ends with unplaced ones', () => {
		expect(answerFieldOrder(version)).toEqual(['f_name', 'f_kind', 'f_tags', 'f_ok', 'f_age', 'f_unplaced'])
	})

	it('pairs every question of the version with its answer', () => {
		const lines = answerLines(version, { f_name: 'Ada', f_kind: 'new' })
		expect(lines.map((l) => [l.label, l.value, l.answered])).toEqual([
			['Name', 'Ada', true],
			['Kind', 'New website', true],
			['Tags', '', false],
			['OK', '', false],
			['Age', '', false],
			['Unplaced', '', false],
		])
	})

	it('previews only answered fields, up to the limit', () => {
		expect(previewLines(version, { f_kind: 'new', f_age: 3, f_name: '' }).map((l) => l.label)).toEqual(['Kind', 'Age'])
		expect(previewLines(version, { f_name: 'a', f_kind: 'new', f_age: 3 }, 1)).toHaveLength(1)
	})
})

describe('inFuture', () => {
	it('words a short wait in minutes or hours', () => {
		expect(inFuture(1000 + 30, 1000)).toBe('in under 2 minutes')
		expect(inFuture(1000 + 300, 1000)).toBe('in 5 minutes')
		expect(inFuture(1000 + 7200, 1000)).toBe('in 2 hours')
		expect(inFuture(10, 1000)).toBe('in under 2 minutes')
	})
})

describe('describeDeliveryError', () => {
	it('translates the server codes into advice', () => {
		expect(describeDeliveryError('secret_missing')).toContain('signing secret')
		expect(describeDeliveryError('no_recipient')).toContain('email address')
		expect(describeDeliveryError('http_404')).toBe('The receiver answered with HTTP 404.')
		expect(describeDeliveryError('request_failed: Host "x" violates local access rules')).toContain('violates local access rules')
		expect(describeDeliveryError('mail_failed: smtp down')).toContain('smtp down')
	})

	it('passes an unknown message through and treats none as empty', () => {
		expect(describeDeliveryError('weird thing')).toBe('weird thing')
		expect(describeDeliveryError(null)).toBe('')
		expect(describeDeliveryError('')).toBe('')
	})

	it('only lets failed deliveries be retried', () => {
		const base = { id: 1, entryId: 1, actionId: 'a', type: 'email', target: null, attempt: 1, error: null, createdAt: 1, updatedAt: 1, nextAttemptAt: null }
		expect(canRetry({ ...base, status: 'failed' })).toBe(true)
		for (const status of ['queued', 'success', 'skipped'] as const) {
			expect(canRetry({ ...base, status })).toBe(false)
		}
	})
})
