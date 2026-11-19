/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormAction, FormField } from '../types.ts'

import { isFilesImage } from '../images.ts'

/**
 * The parts of a form that reach into the owner's Files and Tables (docs/SCHEMA.md section 22.5). They run
 * with the owner's access, so only the owner changes them: an editor sees them read only. Mirrors
 * lib/Forms/Destinations.php, which is what actually refuses the change.
 */

/** A file field kept in Files (the default), or a picture taken from Files. */
export function isDestinationField(field: Pick<FormField, 'type' | 'storage' | 'src'> | undefined): boolean {
	if (!field) {
		return false
	}
	if (field.type === 'file') {
		return field.storage !== 'app'
	}
	return field.type === 'image' && typeof field.src === 'string' && isFilesImage(field.src)
}

/** An action that writes to the owner's Files or Tables. */
export function isDestinationAction(action: Pick<FormAction, 'type'>): boolean {
	return action.type === 'files' || action.type === 'tables'
}

/** The action types an editor may add: not the ones that reach into the owner's Files or Tables. */
export function editorActionTypes(allowed: readonly string[] | undefined, all: readonly string[]): string[] {
	return (allowed ?? all).filter((type) => type !== 'files' && type !== 'tables')
}
