/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OwnerForm } from './types.ts'

import { describe, expect, it } from 'vitest'
import { summarize } from './overview.ts'
import { FORM_STATUS_CLOSED, FORM_STATUS_PUBLISHED, FORM_STATUS_UNPUBLISHED } from './types.ts'

let nextId = 1

function form(patch: Partial<OwnerForm> = {}): OwnerForm {
	const id = nextId++
	return {
		id,
		title: 'Form ' + id,
		description: null,
		status: FORM_STATUS_PUBLISHED,
		publicToken: 'tok' + id,
		latestVersionId: id,
		createdAt: 1000,
		updatedAt: 1000,
		hasUnpublishedChanges: false,
		newCount: 0,
		...patch,
	}
}

function availability(state: 'open' | 'scheduled' | 'ended' | 'full') {
	return { state, opensAt: null, closesAt: null, cap: 0, responses: 0 }
}

describe('the overview of all forms', () => {
	it('counts forms by status and leaves archived ones out', () => {
		const summary = summarize([
			form(),
			form(),
			form({ status: FORM_STATUS_UNPUBLISHED, latestVersionId: null }),
			form({ status: FORM_STATUS_CLOSED }),
			form({ archivedAt: 5, newCount: 9 }),
		])
		expect(summary).toMatchObject({ total: 4, published: 2, drafts: 1, closed: 1, newResponses: 0 })
	})

	it('adds up new responses', () => {
		expect(summarize([form({ newCount: 2 }), form({ newCount: 3 }), form()]).newResponses).toBe(5)
	})

	it('flags forms with new responses first, the busiest first', () => {
		const quiet = form({ availability: availability('full') })
		const some = form({ newCount: 1 })
		const many = form({ newCount: 7 })
		const order = summarize([quiet, some, many]).attention.map((item) => item.form.id)
		expect(order).toEqual([many.id, some.id, quiet.id])
	})

	it('flags a published form that is full or ended, but not a closed one', () => {
		const full = form({ availability: availability('full') })
		const ended = form({ availability: availability('ended') })
		const closed = form({ status: FORM_STATUS_CLOSED, availability: availability('ended') })
		const scheduled = form({ availability: availability('scheduled') })
		const items = summarize([full, ended, closed, scheduled]).attention
		expect(items.map((item) => [item.form.id, item.reasons])).toEqual([
			[full.id, ['full']],
			[ended.id, ['ended']],
		])
	})

	it('flags unpublished changes only on a form that was published before', () => {
		const changed = form({ hasUnpublishedChanges: true })
		const draft = form({ status: FORM_STATUS_UNPUBLISHED, latestVersionId: null, hasUnpublishedChanges: true })
		expect(summarize([changed, draft]).attention.map((item) => item.form.id)).toEqual([changed.id])
	})

	it('lists every reason a form has', () => {
		const busy = form({ newCount: 2, availability: availability('full'), hasUnpublishedChanges: true })
		expect(summarize([busy]).attention[0].reasons).toEqual(['new', 'full', 'unpublished'])
	})

	it('lists the five most recently edited forms, newest first', () => {
		const forms = [1, 2, 3, 4, 5, 6, 7].map((day) => form({ updatedAt: day * 100 }))
		expect(summarize(forms).recent.map((item) => item.updatedAt)).toEqual([700, 600, 500, 400, 300])
	})

	it('has nothing to say for no forms', () => {
		expect(summarize([])).toEqual({ total: 0, published: 0, drafts: 0, closed: 0, newResponses: 0, attention: [], recent: [] })
	})
})
