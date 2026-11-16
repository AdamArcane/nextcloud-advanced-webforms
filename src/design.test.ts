/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { progressColorStyle, progressLabel, progressStyle, submitAlign } from './design.ts'

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

describe('page progress', () => {
	it('is a bar with the step number unless the design says otherwise', () => {
		expect(progressStyle(undefined)).toBe('bar')
		expect(progressStyle({ progress: 'steps' })).toBe('steps')
		expect(progressStyle({ progress: 'none' })).toBe('none')
		expect(progressLabel({})).toBe('step')
		expect(progressLabel({ progressLabel: 'title' })).toBe('title')
		expect(progressLabel({ progressLabel: 'percent' })).toBe('percent')
		expect(progressLabel({ progressLabel: 'none' })).toBe('none')
	})

	it('has its own colour only when the design gives a plain one', () => {
		expect(progressColorStyle({ accentColor: '#112233' })).toEqual({})
		expect(progressColorStyle({ progressColor: 'red; x' })).toEqual({})
		const own = progressColorStyle({ progressColor: '#ffffff' })
		expect(own['--color-primary-element']).toBe('#ffffff')
		expect(own['--color-primary-element-text']).toBe('#000000')
	})

	it('never passes on a value it does not know', () => {
		expect(progressStyle({ progress: 'x' as never })).toBe('bar')
		expect(progressLabel({ progressLabel: 'x' as never })).toBe('step')
	})
})
