/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

// Kept separate from vite.config.js so tests do not load the Nextcloud build plugins.
// Component tests opt into a DOM with `// @vitest-environment happy-dom`.
export default defineConfig({
	plugins: [vue()],
	test: {
		include: ['src/**/*.test.ts'],
		environment: 'node',
		// @nextcloud/vue ships CSS imports that plain Node cannot load; let Vite handle them.
		server: { deps: { inline: [/@nextcloud\/vue/] } },
	},
})
