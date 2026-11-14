/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { acceptAttribute, allowedExtensions, formatSize, localProblem, maxBytes } from './uploads.ts'

describe('upload helpers', () => {
	it('accepts images and documents by default', () => {
		const field = { type: 'file' }
		expect(localProblem(field, { name: 'cv.PDF', size: 100 })).toBeNull()
		expect(localProblem(field, { name: 'a.zip', size: 100 })).toBe('upload_type_not_allowed')
		expect(localProblem(field, { name: 'noext', size: 100 })).toBe('upload_type_not_allowed')
		expect(localProblem(field, { name: 'a.png', size: 0 })).toBe('upload_empty')
	})

	it('follows the field limits and the server limit', () => {
		const field = { type: 'file', maxSizeMb: 2 }
		expect(maxBytes(field)).toBe(2 * 1024 * 1024)
		expect(maxBytes(field, 1024 * 1024)).toBe(1024 * 1024)
		expect(localProblem(field, { name: 'a.png', size: 3 * 1024 * 1024 })).toBe('upload_too_large')
		expect(localProblem(field, { name: 'a.png', size: 1024 * 1024 + 1 }, 1024 * 1024)).toBe('upload_too_large')
	})

	it('combines groups with extra extensions and never allows dangerous types', () => {
		const field = { type: 'file', accept: ['archives', '.dwg', '.php'] }
		expect(allowedExtensions(field)).toContain('zip')
		expect(allowedExtensions(field)).toContain('dwg')
		expect(allowedExtensions(field)).not.toContain('php')
		expect(acceptAttribute({ type: 'file', accept: ['.dwg'] })).toBe('.dwg')
	})

	it('formats sizes for people', () => {
		expect(formatSize(500)).toBe('500 B')
		expect(formatSize(2048)).toBe('2 KB')
		expect(formatSize(1.5 * 1024 * 1024)).toBe('1.5 MB')
		expect(formatSize(25 * 1024 * 1024)).toBe('25 MB')
	})
})
