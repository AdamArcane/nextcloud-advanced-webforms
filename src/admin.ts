/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import AdminApp from './AdminApp.vue'

const root = document.getElementById('advancedforms-admin')
if (root) {
	createApp(AdminApp).mount(root)
}
