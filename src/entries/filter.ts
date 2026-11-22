/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// What the owner is looking for in the responses (docs/SCHEMA.md section 17).

import type { AllCondition, AnyCondition } from '../rules/types.ts'

export type Respondent = 'any' | 'signed_in' | 'anonymous'
export type EntrySort = 'newest' | 'oldest'

export interface EntryFilterState {
	/** Free text, matched against answers (choices by their label) and the signed-in account. */
	text: string
	/** `YYYY-MM-DD`, UTC, or empty. */
	from: string
	to: string
	respondent: Respondent
	/** A condition on the answers, written like a rule. */
	where: AllCondition | AnyCondition | null
	/** Only responses with one of these statuses, where `none` means without one. Empty means any. */
	statuses: string[]
	/** Only responses on the waiting list (docs/SCHEMA.md section 3.2). */
	waitlisted?: boolean
	sort: EntrySort
}

/** In `statuses`: responses that have no status. */
export const NO_STATUS = 'none'

export const EMPTY_FILTER: EntryFilterState = {
	text: '',
	from: '',
	to: '',
	respondent: 'any',
	where: null,
	statuses: [],
	sort: 'newest',
}

/** Whether anything narrows the list. The order alone does not. */
export function isFilterActive(filter: EntryFilterState): boolean {
	return filter.text.trim() !== '' || filter.from !== '' || filter.to !== '' || filter.respondent !== 'any' || filter.where !== null || filter.statuses.length > 0 || filter.waitlisted === true
}

/** The query parameters for the server, leaving out whatever is at its default. */
export function filterParams(filter: EntryFilterState): Record<string, string> {
	const params: Record<string, string> = {}
	if (filter.text.trim() !== '') {
		params.q = filter.text.trim()
	}
	if (filter.from !== '') {
		params.from = filter.from
	}
	if (filter.to !== '') {
		params.to = filter.to
	}
	if (filter.respondent !== 'any') {
		params.respondent = filter.respondent
	}
	if (filter.where !== null) {
		params.where = JSON.stringify(filter.where)
	}
	if (filter.statuses.length > 0) {
		params.status = filter.statuses.join(',')
	}
	if (filter.waitlisted === true) {
		params.waitlisted = 'true'
	}
	if (filter.sort !== 'newest') {
		params.sort = filter.sort
	}
	return params
}

/** The end of the range cannot come before its start. */
export function rangeInvalid(filter: EntryFilterState): boolean {
	return filter.from !== '' && filter.to !== '' && filter.from > filter.to
}
