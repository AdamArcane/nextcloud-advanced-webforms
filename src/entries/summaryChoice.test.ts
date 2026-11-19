// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it } from 'vitest'
import { TIMELINE_CARD, loadHiddenCards, saveHiddenCards, toggleCard } from './summaryChoice.ts'

beforeEach(() => window.localStorage.clear())

describe('summary choice', () => {
	it('hides nothing until a person chooses', () => {
		expect(loadHiddenCards(7)).toEqual([])
	})

	it('keeps what was hidden, for that form only', () => {
		saveHiddenCards(7, ['f_a', TIMELINE_CARD])
		expect(loadHiddenCards(7)).toEqual(['f_a', TIMELINE_CARD])
		expect(loadHiddenCards(8)).toEqual([])
	})

	it('forgets the choice when everything is shown again', () => {
		saveHiddenCards(7, ['f_a'])
		saveHiddenCards(7, [])
		expect(window.localStorage.getItem('webforms.summary.7')).toBeNull()
	})

	it('ignores a damaged choice', () => {
		window.localStorage.setItem('webforms.summary.7', '{nope')
		expect(loadHiddenCards(7)).toEqual([])
		window.localStorage.setItem('webforms.summary.7', JSON.stringify(['f_a', 3, null]))
		expect(loadHiddenCards(7)).toEqual(['f_a'])
	})

	it('toggles one card', () => {
		expect(toggleCard([], 'f_a', false)).toEqual(['f_a'])
		expect(toggleCard(['f_a'], 'f_a', true)).toEqual([])
		expect(toggleCard(['f_a'], 'f_a', false)).toEqual(['f_a'])
	})
})
