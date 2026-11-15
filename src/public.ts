/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import PublicApp from './PublicApp.vue'

const root = document.getElementById('webforms-public')
if (root) {
	createApp(PublicApp, { token: root.dataset.token ?? '' }).mount(root)
}
