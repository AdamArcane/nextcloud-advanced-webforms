/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// New responses and their statuses (docs/SCHEMA.md section 20).

import type { EntryRecord, ResponseStatus } from '../types.ts'

import { t } from '@nextcloud/l10n'

/** The statuses a server starts with. Their names are translated until an administrator renames them. */
const DEFAULT_LABELS: Record<string, () => string> = {
	'in_progress:In progress': () => t('webforms', 'In progress'),
	'done:Done': () => t('webforms', 'Done'),
	'spam:Spam': () => t('webforms', 'Spam'),
}

export function statusLabel(status: ResponseStatus): string {
	return DEFAULT_LABELS[status.id + ':' + status.label]?.() ?? status.label
}

/** The status with this id. A response can still carry the id of one that was deleted, which reads as none. */
export function findStatus(statuses: readonly ResponseStatus[], id: string | null | undefined): ResponseStatus | null {
	return id ? (statuses.find((status) => status.id === id) ?? null) : null
}

/**
 * Whether a response arrived after the owner last looked at the list. `seenAt` is that moment in unix
 * seconds, null when the list was never opened (everything is new), or undefined while it is not known.
 */
export function isNewResponse(entry: Pick<EntryRecord, 'submittedAt'>, seenAt: number | null | undefined): boolean {
	return seenAt !== undefined && (seenAt === null || entry.submittedAt > seenAt)
}

const ID_PATTERN = /^[a-z][a-z0-9_]{2,31}$/

/**
 * An id for a new status, made from its name: lowercase letters, digits and underscores. It is never
 * changed afterwards, so renaming a status keeps it on the responses that have it.
 */
export function statusIdFromLabel(label: string, taken: readonly string[]): string {
	let base = label.normalize('NFKD').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
	if (!/^[a-z]/.test(base)) {
		base = 'status_' + base
	}
	base = base.replace(/_+$/, '').slice(0, 28)
	if (!ID_PATTERN.test(base) || base === 'none') {
		base = 'status'
	}
	let id = base
	for (let n = 2; taken.includes(id) || id === 'none'; n++) {
		id = base + '_' + n
	}
	return id
}
