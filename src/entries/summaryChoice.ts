/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Which cards the Summary shows. A person hides the ones they do not want; what they hide is kept in
// their browser for that form, like the response columns (entries/columns.ts). Keeping what is hidden,
// not what is shown, means a question added later appears without anyone having to turn it on.

/** The id the responses-over-time chart has in the choice. No field id can look like this. */
export const TIMELINE_CARD = '@timeline'

const STORAGE_PREFIX = 'webforms.summary.'

/** The cards this person hid for this form. Nothing is hidden until they choose. */
export function loadHiddenCards(formId: number): string[] {
	try {
		const raw = window.localStorage.getItem(STORAGE_PREFIX + formId)
		const parsed: unknown = raw === null ? null : JSON.parse(raw)
		return Array.isArray(parsed) ? parsed.filter((id): id is string => typeof id === 'string') : []
	} catch {
		return []
	}
}

export function saveHiddenCards(formId: number, hidden: string[]): void {
	try {
		if (hidden.length === 0) {
			window.localStorage.removeItem(STORAGE_PREFIX + formId)
		} else {
			window.localStorage.setItem(STORAGE_PREFIX + formId, JSON.stringify(hidden))
		}
	} catch {
		// Private windows and blocked storage: the choice just lasts until the page is closed.
	}
}

/** Hide or show one card, returning the new list of hidden ones. */
export function toggleCard(hidden: readonly string[], id: string, shown: boolean): string[] {
	const rest = hidden.filter((entry) => entry !== id)
	return shown ? rest : [...rest, id]
}
