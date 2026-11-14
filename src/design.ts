/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormDesign } from './types.ts'

export const DEFAULT_ACCENT = '#0082c9'
export const DEFAULT_BACKGROUND_COLOR = '#f5f5f7'

export const WIDTHS = { narrow: 600, standard: 760, wide: 980 } as const

const HEX = /^#[0-9a-fA-F]{6}$/

/** Black or white, whichever reads better on `hex` (WCAG relative luminance). */
export function textOn(hex: string): string {
	const channel = (offset: number) => {
		const value = parseInt(hex.slice(offset, offset + 2), 16) / 255
		return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
	}
	const luminance = 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5)
	return luminance > 0.4 ? '#000000' : '#ffffff'
}

/** Darken a `#rrggbb` colour by `amount` (0 to 1), for hover states. */
function darken(hex: string, amount: number): string {
	const part = (offset: number) => Math.round(parseInt(hex.slice(offset, offset + 2), 16) * (1 - amount)).toString(16).padStart(2, '0')
	return '#' + part(1) + part(3) + part(5)
}

/** A colour from the definition, or null if it is not a plain `#rrggbb` (the server enforces this too). */
export function safeColor(value: string | undefined): string | null {
	return value !== undefined && HEX.test(value) ? value : null
}

/** Inline CSS variables for the form column. Only validated values reach CSS. */
export function accentStyle(design: FormDesign | undefined): Record<string, string> {
	return colorVars(safeColor(design?.accentColor))
}

/** The accent variables for the submit button: its own colour, else the accent colour. */
export function submitButtonStyle(design: FormDesign | undefined): Record<string, string> {
	return colorVars(safeColor(design?.buttonColor) ?? safeColor(design?.accentColor))
}

function colorVars(accent: string | null): Record<string, string> {
	if (!accent) {
		return {}
	}
	return {
		'--color-primary-element': accent,
		'--color-primary-element-hover': darken(accent, 0.12),
		'--color-primary-element-text': textOn(accent),
		'--color-primary-element-text-hover': textOn(accent),
	}
}

/** The colour to paint behind the whole page, or null to keep Nextcloud's own background. */
export function pageBackground(design: FormDesign | undefined): string | null {
	switch (design?.background) {
		case 'plain':
			return 'var(--color-background-hover, #f5f5f7)'
		case 'color':
			return safeColor(design.backgroundColor) ?? DEFAULT_BACKGROUND_COLOR
		default:
			return null
	}
}
