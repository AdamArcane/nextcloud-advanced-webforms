/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig } from 'vitest/config'

// Kept separate from vite.config.js so tests do not load the Nextcloud build plugins.
export default defineConfig({
	test: {
		include: ['src/**/*.test.ts'],
		environment: 'node',
	},
})
