/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The eight colours a status can have (docs/SCHEMA.md section 20.2), one place for every screen.
// Picked to stay readable as a dot or an outline on light and dark backgrounds.

export const STATUS_COLORS: Record<string, string> = {
	gray: '#8a8a8a',
	red: '#d9363e',
	orange: '#e9730c',
	yellow: '#c9a000',
	green: '#2d9d4f',
	teal: '#0f9b8e',
	blue: '#0082c9',
	purple: '#8b5cf6',
}

/** The colour as a CSS value. A colour the server sends that this build does not know reads as gray. */
export function statusColor(color: string | null | undefined): string {
	return STATUS_COLORS[color ?? ''] ?? STATUS_COLORS.gray
}
