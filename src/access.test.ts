// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it } from 'vitest'
import { clearAccess, getAccess, setAccess } from './access.ts'

beforeEach(() => {
	clearAccess('tok')
	clearAccess('other')
})

describe('the proof that a link password was given', () => {
	it('is empty until one is set, and is kept per form', () => {
		expect(getAccess('tok')).toBe('')
		setAccess('tok', '1700000000.abc')
		expect(getAccess('tok')).toBe('1700000000.abc')
		expect(getAccess('other')).toBe('')
	})

	it('survives a reload of the tab, through session storage', () => {
		setAccess('tok', '1700000000.abc')
		expect(window.sessionStorage.getItem('webforms.access.tok')).toBe('1700000000.abc')
	})

	it('is forgotten when cleared', () => {
		setAccess('tok', '1700000000.abc')
		clearAccess('tok')
		expect(getAccess('tok')).toBe('')
		expect(window.sessionStorage.getItem('webforms.access.tok')).toBeNull()
	})

	it('still works for this visit when storage is blocked', () => {
		const original = Object.getOwnPropertyDescriptor(window, 'sessionStorage')!
		Object.defineProperty(window, 'sessionStorage', { configurable: true, get: () => { throw new Error('blocked') } })
		try {
			setAccess('tok', 'held')
			expect(getAccess('tok')).toBe('held')
		} finally {
			Object.defineProperty(window, 'sessionStorage', original)
		}
	})
})
