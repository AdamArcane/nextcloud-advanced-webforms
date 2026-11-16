/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { STATUS_COLORS, statusColor } from './statusColors.ts'

describe('statusColor', () => {
	it('has the eight colours the server allows', () => {
		expect(Object.keys(STATUS_COLORS)).toEqual(['gray', 'red', 'orange', 'yellow', 'green', 'teal', 'blue', 'purple'])
	})

	it('reads a colour this build does not know, or none, as gray', () => {
		expect(statusColor('teal')).toBe(STATUS_COLORS.teal)
		expect(statusColor('mauve')).toBe(STATUS_COLORS.gray)
		expect(statusColor(null)).toBe(STATUS_COLORS.gray)
	})
})
