/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { submitAlign } from './design.ts'

describe('submit button position', () => {
	it('is left unless the design says otherwise', () => {
		expect(submitAlign(undefined)).toBe('left')
		expect(submitAlign({})).toBe('left')
		expect(submitAlign({ submitAlign: 'center' })).toBe('center')
		expect(submitAlign({ submitAlign: 'right' })).toBe('right')
		expect(submitAlign({ submitAlign: 'justify' })).toBe('justify')
	})

	it('never passes on a value it does not know', () => {
		expect(submitAlign({ submitAlign: 'x" onload="alert(1)' as never })).toBe('left')
	})
})
