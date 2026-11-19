/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OwnerForm } from './types.ts'

import { FORM_STATUS_CLOSED, FORM_STATUS_PUBLISHED, FORM_STATUS_UNPUBLISHED } from './types.ts'

/** Why a form is on the "needs attention" list. Ordered by how much the owner should care. */
export type AttentionReason = 'new' | 'full' | 'ended' | 'unpublished'

export interface AttentionItem {
	form: OwnerForm
	reasons: AttentionReason[]
}

export interface Overview {
	total: number
	published: number
	drafts: number
	closed: number
	/** Responses that arrived since the owner last opened each form's list. */
	newResponses: number
	attention: AttentionItem[]
	recent: OwnerForm[]
}

const RECENT_LIMIT = 5

function reasonsFor(form: OwnerForm): AttentionReason[] {
	const reasons: AttentionReason[] = []
	if ((form.newCount ?? 0) > 0) {
		reasons.push('new')
	}
	// A closed form is closed on purpose. Only a published form that stopped by itself is worth a flag.
	if (form.status === FORM_STATUS_PUBLISHED) {
		if (form.availability?.state === 'full') {
			reasons.push('full')
		} else if (form.availability?.state === 'ended') {
			reasons.push('ended')
		}
	}
	// A form that was never published is simply a draft, so only a published one can have a newer draft.
	if (form.latestVersionId !== null && form.hasUnpublishedChanges === true) {
		reasons.push('unpublished')
	}
	return reasons
}

/** Boils the owner's forms down to counts, the ones that need a look, and the ones touched last. Archived forms are left out. */
export function summarize(forms: OwnerForm[]): Overview {
	const active = forms.filter((form) => form.archivedAt == null)
	const attention: AttentionItem[] = []
	let newResponses = 0
	for (const form of active) {
		newResponses += form.newCount ?? 0
		const reasons = reasonsFor(form)
		if (reasons.length > 0) {
			attention.push({ form, reasons })
		}
	}
	// New responses first, then by how many, then the rest in the order the list has them.
	attention.sort((a, b) => Number(b.reasons.includes('new')) - Number(a.reasons.includes('new'))
		|| (b.form.newCount ?? 0) - (a.form.newCount ?? 0))
	return {
		total: active.length,
		published: active.filter((form) => form.status === FORM_STATUS_PUBLISHED).length,
		drafts: active.filter((form) => form.status === FORM_STATUS_UNPUBLISHED).length,
		closed: active.filter((form) => form.status === FORM_STATUS_CLOSED).length,
		newResponses,
		attention,
		recent: [...active].sort((a, b) => b.updatedAt - a.updatedAt).slice(0, RECENT_LIMIT),
	}
}
