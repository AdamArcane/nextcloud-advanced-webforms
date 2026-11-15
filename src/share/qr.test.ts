/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { qrModules, qrSvg } from './qr.ts'

const LINK = 'https://cloud.example.com/apps/webforms/f/contact'

/** The three finder patterns: a dark 7 by 7 ring with a dark 3 by 3 centre. */
function hasFinder(rows: boolean[][], top: number, left: number): boolean {
	for (let row = 0; row < 7; row++) {
		for (let column = 0; column < 7; column++) {
			const edge = row === 0 || row === 6 || column === 0 || column === 6
			const centre = row >= 2 && row <= 4 && column >= 2 && column <= 4
			if (rows[top + row][left + column] !== (edge || centre)) {
				return false
			}
		}
	}
	return true
}

describe('QR code', () => {
	it('is a square with the three finder patterns', () => {
		const rows = qrModules(LINK)
		const size = rows.length
		// Version n has 17 + 4n modules a side.
		expect((size - 17) % 4).toBe(0)
		expect(rows.every((cells) => cells.length === size)).toBe(true)
		expect(hasFinder(rows, 0, 0)).toBe(true)
		expect(hasFinder(rows, 0, size - 7)).toBe(true)
		expect(hasFinder(rows, size - 7, 0)).toBe(true)
	})

	it('differs for a different link and is the same for the same link', () => {
		expect(qrSvg(LINK)).toBe(qrSvg(LINK))
		expect(qrSvg(LINK)).not.toBe(qrSvg(LINK + '2'))
	})

	it('is black on white with a quiet zone of four modules', () => {
		const size = qrModules(LINK).length
		const svg = qrSvg(LINK)
		expect(svg).toContain('viewBox="0 0 ' + (size + 8) + ' ' + (size + 8) + '"')
		expect(svg).toContain('fill="#ffffff"')
		// The first dark run starts at the corner of the top left finder, inside the quiet zone.
		expect(svg).toContain('d="M4 4h7v1h-7z')
	})
})
