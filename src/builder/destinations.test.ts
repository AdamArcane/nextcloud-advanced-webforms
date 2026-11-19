// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { editorActionTypes, isDestinationAction, isDestinationField } from './destinations.ts'

describe('what reaches into the owner\'s Files and Tables', () => {
	it('counts a file field kept in Files, which is the default', () => {
		expect(isDestinationField({ type: 'file' })).toBe(true)
		expect(isDestinationField({ type: 'file', storage: 'files' })).toBe(true)
	})

	it('does not count a file field kept in the app', () => {
		expect(isDestinationField({ type: 'file', storage: 'app' })).toBe(false)
	})

	it('counts a picture from Files and not one from the web or a text field', () => {
		expect(isDestinationField({ type: 'image', src: 'Pictures/logo.png' })).toBe(true)
		expect(isDestinationField({ type: 'image', src: 'https://example.com/a.png' })).toBe(false)
		expect(isDestinationField({ type: 'text' })).toBe(false)
		expect(isDestinationField(undefined)).toBe(false)
	})

	it('counts the files and tables actions and no others', () => {
		expect(isDestinationAction({ type: 'files' })).toBe(true)
		expect(isDestinationAction({ type: 'tables' })).toBe(true)
		for (const type of ['email', 'webhook', 'message', 'redirect'] as const) {
			expect(isDestinationAction({ type })).toBe(false)
		}
	})

	it('leaves an editor the action types that stay inside the form', () => {
		const all = ['message', 'redirect', 'email', 'webhook', 'tables', 'files']
		expect(editorActionTypes(undefined, all)).toEqual(['message', 'redirect', 'email', 'webhook'])
		expect(editorActionTypes(['email', 'files'], all)).toEqual(['email'])
	})
})
