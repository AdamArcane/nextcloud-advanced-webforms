/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { loadNavOpen, saveNavOpen } from './navState.ts'

function memory(initial?: string) {
	let value = initial ?? null
	return {
		getItem: () => value,
		setItem: (_key: string, next: string) => { value = next },
	}
}

describe('remembering which sections are open', () => {
	it('gives back what was saved', () => {
		const store = memory()
		saveNavOpen({ mine: false, archived: true }, store)
		expect(loadNavOpen(store)).toEqual({ mine: false, archived: true })
	})

	it('starts empty, so every section keeps its default', () => {
		expect(loadNavOpen(memory())).toEqual({})
	})

	it('ignores damaged or odd values', () => {
		expect(loadNavOpen(memory('not json'))).toEqual({})
		expect(loadNavOpen(memory('[true]'))).toEqual({})
		expect(loadNavOpen(memory('{"mine":"no","shared":false}'))).toEqual({ shared: false })
	})

	it('copes with storage that is not there or refuses', () => {
		expect(loadNavOpen(null)).toEqual({})
		expect(() => saveNavOpen({ mine: true }, { setItem: () => { throw new Error('blocked') } })).not.toThrow()
		expect(() => saveNavOpen({ mine: true }, null)).not.toThrow()
	})
})
