/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { HEIGHT_MESSAGE } from '../embedded.ts'

function escapeHtml(text: string): string {
	return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

export const DEFAULT_EMBED_HEIGHT = 600

/** Plain HTML, no script: a frame of a fixed height that scrolls when the form is taller. */
export function iframeSnippet(embedUrl: string, title: string, height: number = DEFAULT_EMBED_HEIGHT): string {
	return '<iframe src="' + escapeHtml(embedUrl) + '" title="' + escapeHtml(title) + '" width="100%" height="' + Math.round(height) + '" style="border:0;max-width:100%" loading="lazy"></iframe>'
}

/** The frame plus a few lines of script that keep it as tall as the form, so it never scrolls on its own. */
export function autoHeightSnippet(embedUrl: string, title: string, frameId: string, height: number = DEFAULT_EMBED_HEIGHT): string {
	const id = frameId.replace(/[^A-Za-z0-9_-]/g, '')
	return [
		'<iframe id="' + id + '" src="' + escapeHtml(embedUrl) + '" title="' + escapeHtml(title) + '" width="100%" height="' + Math.round(height) + '" style="border:0;max-width:100%" loading="lazy"></iframe>',
		'<script>',
		'window.addEventListener("message", function (event) {',
		'  var frame = document.getElementById("' + id + '");',
		'  if (frame && event.source === frame.contentWindow && event.data && event.data.type === "' + HEIGHT_MESSAGE + '" && event.data.height > 0) {',
		'    frame.style.height = Math.ceil(event.data.height) + "px";',
		'  }',
		'});',
		'</' + 'script>',
	].join('\n')
}

/** What the person typed, one website per line (or separated by commas or spaces), as a list. */
export function parseOrigins(text: string): string[] {
	return text.split(/[\s,]+/).map((part) => part.trim().replace(/\/+$/, '')).filter((part) => part !== '')
}

const SLUG = /^[a-z0-9][a-z0-9-]{1,62}[a-z0-9]$/

/** The same rule the server applies (lib/Forms/ShareSettings.php), so a typo is caught before saving. */
export function slugLooksValid(slug: string): boolean {
	return SLUG.test(slug) && !slug.includes('--')
}

/** Turn what the person typed into something close to a valid address: "Summer Camp!" becomes "summer-camp". */
export function tidySlug(text: string): string {
	return text.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 64).replace(/-+$/, '')
}
