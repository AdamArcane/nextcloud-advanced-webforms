/**
 * SPDX-FileCopyrightText: 2026 Adam and Advanced Forms contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

export default createAppConfig({
	main: resolve(join('src', 'main.ts')),
})
