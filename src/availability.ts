/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OwnerForm } from './types.ts'

import { n, t } from '@nextcloud/l10n'
import { FORM_STATUS_CLOSED, FORM_STATUS_PUBLISHED } from './types.ts'

/**
 * Response limit and opening dates as the owner sees them (docs/SCHEMA.md section 3.1). The server
 * works out the state; this only words it.
 */

function formatTime(seconds: number): string {
	return new Date(seconds * 1000).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

/** Unix seconds as the value of a `datetime-local` input, in the browser's time zone. */
export function toLocalInput(seconds: number | undefined): string {
	if (seconds === undefined) {
		return ''
	}
	const date = new Date(seconds * 1000)
	const pad = (value: number): string => String(value).padStart(2, '0')
	return date.getFullYear().toString().padStart(4, '0') + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
		+ 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes())
}

/** The value of a `datetime-local` input as unix seconds, or undefined when it is empty or not a date. */
export function fromLocalInput(value: string): number | undefined {
	if (value === '') {
		return undefined
	}
	const seconds = Math.floor(new Date(value).getTime() / 1000)
	return Number.isFinite(seconds) && seconds >= 1 ? seconds : undefined
}

/** One or two words for the forms list. */
export function statusText(form: OwnerForm): string {
	if (form.status === FORM_STATUS_CLOSED) {
		return t('webforms', 'Closed')
	}
	if (form.status !== FORM_STATUS_PUBLISHED) {
		return t('webforms', 'Draft')
	}
	switch (form.availability?.state) {
		case 'scheduled': return t('webforms', 'Scheduled')
		case 'ended': return t('webforms', 'Ended')
		case 'full': return t('webforms', 'Full')
		default: return t('webforms', 'Published')
	}
}

/**
 * A sentence or two on whether the published form takes responses now, and how many it has against
 * its limit. Null when there is nothing to say: never published, or no limit and no dates.
 */
export function availabilityNote(form: OwnerForm): string | null {
	const availability = form.availability
	if (!availability || form.status !== FORM_STATUS_PUBLISHED || form.archivedAt != null) {
		return null
	}
	const { state, opensAt, closesAt, cap, responses } = availability
	if (state === 'open' && closesAt === null && cap === 0) {
		return null
	}
	const parts: string[] = []
	if (state === 'scheduled' && opensAt !== null) {
		parts.push(t('webforms', 'Scheduled: opens {time}.', { time: formatTime(opensAt) }))
	} else if (state === 'ended' && closesAt !== null) {
		parts.push(t('webforms', 'Ended: closed {time}.', { time: formatTime(closesAt) }))
	} else if (state === 'full') {
		parts.push(t('webforms', 'Full: it takes no more responses.'))
	} else if (closesAt !== null) {
		parts.push(t('webforms', 'Open until {time}.', { time: formatTime(closesAt) }))
	} else {
		parts.push(t('webforms', 'Open.'))
	}
	if (cap > 0) {
		parts.push(n('webforms', '{responses} of {count} response.', '{responses} of {count} responses.', cap, { responses, count: cap }))
	}
	return parts.join(' ')
}
