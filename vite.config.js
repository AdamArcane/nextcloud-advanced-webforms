/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

export default createAppConfig({
	main: resolve(join('src', 'main.ts')),
	public: resolve(join('src', 'public.ts')),
	admin: resolve(join('src', 'admin.ts')),
})
