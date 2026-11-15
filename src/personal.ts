/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import PersonalApp from './PersonalApp.vue'

const root = document.getElementById('webforms-personal')
if (root) {
	createApp(PersonalApp).mount(root)
}
