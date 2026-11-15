/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Put text on the clipboard. */
export async function copyText(text: string): Promise<void> {
	try {
		await navigator.clipboard.writeText(text)
	} catch {
		// navigator.clipboard only exists on secure origins, and a dev server is often plain http.
		const area = document.createElement('textarea')
		area.value = text
		document.body.append(area)
		area.select()
		document.execCommand('copy')
		area.remove()
	}
}
