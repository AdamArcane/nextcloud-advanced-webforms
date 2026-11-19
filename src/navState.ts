/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Which sections of the form list are open, kept in this browser only. Missing means the section's default. */
export type NavOpen = Record<string, boolean>

const KEY = 'webforms:nav-open'

/** What was saved, or nothing when there is nothing, the browser refuses storage, or the value is damaged. */
export function loadNavOpen(storage: Pick<Storage, 'getItem'> | null = safeStorage()): NavOpen {
	try {
		const parsed: unknown = JSON.parse(storage?.getItem(KEY) ?? '{}')
		if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
			return {}
		}
		return Object.fromEntries(Object.entries(parsed).filter((entry): entry is [string, boolean] => typeof entry[1] === 'boolean'))
	} catch {
		return {}
	}
}

export function saveNavOpen(open: NavOpen, storage: Pick<Storage, 'setItem'> | null = safeStorage()): void {
	try {
		storage?.setItem(KEY, JSON.stringify(open))
	} catch {
		// Private windows and blocked storage: the list simply opens as usual next time.
	}
}

function safeStorage(): Storage | null {
	try {
		return window.localStorage
	} catch {
		return null
	}
}
