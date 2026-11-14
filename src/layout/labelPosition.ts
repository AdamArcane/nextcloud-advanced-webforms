/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormField, FormSettings, LabelPosition } from '../types.ts'

export const LABEL_POSITIONS: readonly LabelPosition[] = ['above', 'inline', 'hidden']

/** Types whose label belongs to a group or to the control itself, so a side label reads badly (docs/SCHEMA.md 4.2). */
const NO_INLINE_TYPES = new Set(['textarea', 'radio', 'checkboxes', 'checkbox'])

export function supportsInline(type: string): boolean {
	return !NO_INLINE_TYPES.has(type)
}

function isPosition(value: unknown): value is LabelPosition {
	return typeof value === 'string' && (LABEL_POSITIONS as readonly string[]).includes(value)
}

/**
 * The position a field asks for: its own `labelPosition`, then the older
 * `hideLabel` flag, then the form default, then `above`.
 */
export function requestedLabelPosition(field: FormField, settings: FormSettings | undefined): LabelPosition {
	if (isPosition(field.labelPosition)) {
		return field.labelPosition
	}
	if (field.hideLabel === true) {
		return 'hidden'
	}
	return isPosition(settings?.labelPosition) ? settings.labelPosition : 'above'
}

/** The position actually drawn: `inline` becomes `above` for types that do not support it. */
export function renderedLabelPosition(field: FormField, settings: FormSettings | undefined): LabelPosition {
	const requested = requestedLabelPosition(field, settings)
	return requested === 'inline' && !supportsInline(field.type) ? 'above' : requested
}
