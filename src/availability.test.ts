/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormAvailability, OwnerForm } from './types.ts'

import { describe, expect, it } from 'vitest'
import { availabilityNote, fromLocalInput, statusText, toLocalInput } from './availability.ts'
import { FORM_STATUS_CLOSED, FORM_STATUS_PUBLISHED, FORM_STATUS_UNPUBLISHED } from './types.ts'

function form(status: number, availability: Partial<FormAvailability> | null, archivedAt: number | null = null): OwnerForm {
	return {
		id: 1,
		title: 'T',
		description: null,
		status,
		publicToken: 'tok',
		latestVersionId: availability === null ? null : 3,
		archivedAt,
		createdAt: 0,
		updatedAt: 0,
		availability: availability === null
			? null
			: { state: 'open', opensAt: null, closesAt: null, cap: 0, responses: 0, ...availability },
	}
}

describe('waiting list wording', () => {
	it('says a full form takes new responses on a waiting list, and how many wait', () => {
		const note = availabilityNote(form(FORM_STATUS_PUBLISHED, { state: 'full', cap: 5, responses: 5, waitlisted: 2, waitsWhenFull: true }))
		expect(note).toBe('Full: new responses join the waiting list. 5 of 5 responses. 2 responses on the waiting list.')
	})

	it('keeps the old wording for a form that closes when full', () => {
		expect(availabilityNote(form(FORM_STATUS_PUBLISHED, { state: 'full', cap: 5, responses: 5 })))
			.toBe('Full: it takes no more responses. 5 of 5 responses.')
	})
})

describe('statusText', () => {
	it('words the state of a published form', () => {
		expect(statusText(form(FORM_STATUS_PUBLISHED, {}))).toBe('Published')
		expect(statusText(form(FORM_STATUS_PUBLISHED, { state: 'scheduled' }))).toBe('Scheduled')
		expect(statusText(form(FORM_STATUS_PUBLISHED, { state: 'ended' }))).toBe('Ended')
		expect(statusText(form(FORM_STATUS_PUBLISHED, { state: 'full' }))).toBe('Full')
	})

	it('lets the owner\'s own status win', () => {
		expect(statusText(form(FORM_STATUS_CLOSED, { state: 'full' }))).toBe('Closed')
		expect(statusText(form(FORM_STATUS_UNPUBLISHED, null))).toBe('Draft')
	})
})

describe('availabilityNote', () => {
	it('says nothing without a limit or dates', () => {
		expect(availabilityNote(form(FORM_STATUS_PUBLISHED, {}))).toBeNull()
		expect(availabilityNote(form(FORM_STATUS_UNPUBLISHED, null))).toBeNull()
	})

	it('says nothing for a closed or archived form', () => {
		expect(availabilityNote(form(FORM_STATUS_CLOSED, { state: 'full', cap: 2, responses: 2 }))).toBeNull()
		expect(availabilityNote(form(FORM_STATUS_PUBLISHED, { state: 'full', cap: 2, responses: 2 }, 1800000000))).toBeNull()
	})

	it('counts responses against the limit', () => {
		expect(availabilityNote(form(FORM_STATUS_PUBLISHED, { cap: 50, responses: 12 }))).toBe('Open. 12 of 50 responses.')
		expect(availabilityNote(form(FORM_STATUS_PUBLISHED, { state: 'full', cap: 50, responses: 50 })))
			.toBe('Full: it takes no more responses. 50 of 50 responses.')
	})

	it('names the opening and closing times', () => {
		expect(availabilityNote(form(FORM_STATUS_PUBLISHED, { state: 'scheduled', opensAt: 1800000000 }))).toMatch(/^Scheduled: opens .+\.$/)
		expect(availabilityNote(form(FORM_STATUS_PUBLISHED, { state: 'ended', closesAt: 1800000000 }))).toMatch(/^Ended: closed .+\.$/)
		expect(availabilityNote(form(FORM_STATUS_PUBLISHED, { closesAt: 1800000000 }))).toMatch(/^Open until .+\.$/)
	})
})

describe('datetime-local values', () => {
	it('round-trips through the browser\'s time zone', () => {
		const seconds = fromLocalInput('2027-03-01T09:30')
		expect(seconds).toBe(Math.floor(new Date(2027, 2, 1, 9, 30).getTime() / 1000))
		expect(toLocalInput(seconds)).toBe('2027-03-01T09:30')
	})

	it('treats an empty or broken value as not set', () => {
		expect(fromLocalInput('')).toBeUndefined()
		expect(fromLocalInput('soon')).toBeUndefined()
		expect(toLocalInput(undefined)).toBe('')
	})
})
