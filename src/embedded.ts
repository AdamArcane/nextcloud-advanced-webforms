/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Whether this page is the embedded form, shown in a frame on another website (docs/SCHEMA.md section
 * 18.3). Set once by the entry script, before anything is mounted.
 */
let embedded = false

export function setEmbedded(value: boolean): void {
	embedded = value
}

export function isEmbedded(): boolean {
	return embedded
}

/**
 * Go to another address after submitting. An embedded form takes the whole page there, not just its
 * frame: a thank-you page inside a small frame is not what a redirect is for.
 */
export function leaveTo(address: string): void {
	if (embedded && window.top && window.top !== window) {
		try {
			window.top.location.href = address
			return
		} catch {
			// The browser did not let the frame move the page. Stay inside the frame.
		}
	}
	window.location.assign(address)
}

/** The message an embedded form sends to the page around it whenever its height changes. */
export const HEIGHT_MESSAGE = 'webforms:height'

/** Keep the page around the frame told about the form's height, so its script can fit the frame. */
export function reportHeight(element: HTMLElement): () => void {
	if (!embedded || window.parent === window || typeof ResizeObserver === 'undefined') {
		return () => {}
	}
	const send = () => {
		// The height says nothing private, and the form does not know which site frames it.
		window.parent.postMessage({ type: HEIGHT_MESSAGE, height: Math.ceil(element.getBoundingClientRect().height) }, '*')
	}
	const observer = new ResizeObserver(send)
	observer.observe(element)
	send()
	return () => observer.disconnect()
}
