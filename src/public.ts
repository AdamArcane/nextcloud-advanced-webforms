/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import { setEmbedded } from './embedded.ts'
import PublicApp from './PublicApp.vue'

const root = document.getElementById('webforms-public')
if (root) {
	const embed = root.dataset.embed === '1'
	setEmbedded(embed)
	// The page styles for a frame (see PublicApp.vue) hang on this, and must apply before the first paint.
	document.documentElement.classList.toggle('af-embed', embed)
	if (embed) {
		// Always the light theme in a frame: the form cannot see how the website around it looks, and a
		// visitor's dark mode would otherwise put a dark form on a light page.
		document.body.setAttribute('data-theme-light', '')
	}
	createApp(PublicApp, { token: root.dataset.token ?? '', embed }).mount(root)
}
