/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { findStatus, isNewResponse, statusIdFromLabel, statusLabel } from './statuses.ts'

const DONE = { id: 'done', label: 'Done', color: 'green' }

describe('new responses', () => {
	it('is new when it arrived after the last look', () => {
		expect(isNewResponse({ submittedAt: 101 }, 100)).toBe(true)
		expect(isNewResponse({ submittedAt: 100 }, 100)).toBe(false)
		expect(isNewResponse({ submittedAt: 99 }, 100)).toBe(false)
	})

	it('is all new when the list was never opened, and nothing while that is not known yet', () => {
		expect(isNewResponse({ submittedAt: 1 }, null)).toBe(true)
		expect(isNewResponse({ submittedAt: 1 }, undefined)).toBe(false)
	})
})

describe('statuses', () => {
	it('finds a status by id, and reads a deleted or missing one as none', () => {
		expect(findStatus([DONE], 'done')).toEqual(DONE)
		expect(findStatus([DONE], 'gone')).toBeNull()
		expect(findStatus([DONE], null)).toBeNull()
	})

	it('shows the name the administrator gave', () => {
		expect(statusLabel({ id: 'done', label: 'Erledigt', color: 'green' })).toBe('Erledigt')
		expect(statusLabel(DONE)).toBe('Done')
	})

	it('makes an id from a name', () => {
		expect(statusIdFromLabel('Called back', [])).toBe('called_back')
		expect(statusIdFromLabel('  Zurückgerufen! ', [])).toBe('zuruckgerufen')
		expect(statusIdFromLabel('2nd look', [])).toBe('status_2nd_look')
		expect(statusIdFromLabel('OK', [])).toBe('status')
		expect(statusIdFromLabel('日本', [])).toBe('status')
		expect(statusIdFromLabel('None', [])).toBe('status')
	})

	it('never repeats an id that is taken', () => {
		expect(statusIdFromLabel('Done', ['done'])).toBe('done_2')
		expect(statusIdFromLabel('Done', ['done', 'done_2'])).toBe('done_3')
	})

	it('keeps a long name inside the length an id may have', () => {
		const id = statusIdFromLabel('a'.repeat(80), ['a'.repeat(28)])
		expect(id).toMatch(/^[a-z][a-z0-9_]{2,31}$/)
	})
})
