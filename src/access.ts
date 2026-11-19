/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The proof that this visitor gave the right password for a form's link (docs/SCHEMA.md section 18.5).
 * It is kept in memory, and in the tab's session storage where that works, so a reload does not ask again.
 * A form in a frame may have no storage at all, and then it asks again after a reload.
 */

const memory = new Map<string, string>()

function key(token: string): string {
	return 'webforms.access.' + token
}

export function getAccess(token: string): string {
	const held = memory.get(token)
	if (held !== undefined) {
		return held
	}
	try {
		return window.sessionStorage.getItem(key(token)) ?? ''
	} catch {
		return ''
	}
}

export function setAccess(token: string, access: string): void {
	memory.set(token, access)
	try {
		if (access === '') {
			window.sessionStorage.removeItem(key(token))
		} else {
			window.sessionStorage.setItem(key(token), access)
		}
	} catch {
		// Memory alone is enough for this visit.
	}
}

export function clearAccess(token: string): void {
	setAccess(token, '')
	memory.delete(token)
}
