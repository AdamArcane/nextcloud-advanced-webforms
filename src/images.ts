/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Where an image block's picture is loaded from (docs/SCHEMA.md section 4.9). Mirrors lib/Forms/ImageSource.php.

import { isEmbedded } from './embedded.ts'
import { url } from './api/http.ts'

/** What respondents get in place of a path in the owner's Files. */
export const FILES_MARKER = '@files'

/** Extensions a picture in Files may have. */
export const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'] as const
export const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'] as const

/** An address on another site. Only https is ever loaded. */
export function isRemoteImage(src: string): boolean {
	return /^https:\/\/[^\s<>"'\\]+$/i.test(src)
}

/** A path in Files that could be a picture: no scheme, and one of the extensions above. */
export function isFilesImage(src: string): boolean {
	const extension = src.split('.').pop()?.toLowerCase() ?? ''
	return src !== '' && !/^[a-z][a-z0-9+.-]*:/i.test(src) && (IMAGE_EXTENSIONS as readonly string[]).includes(extension)
}

/** The picture as the form's owner sees it in the builder: read from their own Files by path. */
export function ownerImageUrl(src: string): string | null {
	if (isRemoteImage(src)) {
		return src
	}
	return isFilesImage(src) ? url('/image') + '?path=' + encodeURIComponent(src.replace(/^\/+/, '')) : null
}

/** The picture as a respondent gets it: the address itself, or this form's own route for a picture in Files. */
export function publicImageUrl(src: string, token: string, fieldId: string, versionId: number): string | null {
	if (isRemoteImage(src)) {
		return src
	}
	if (src !== FILES_MARKER) {
		return null
	}
	// The version makes a newly published picture replace a cached one.
	return url('/public/' + encodeURIComponent(token) + (isEmbedded() ? '/embed' : '') + '/image/' + encodeURIComponent(fieldId)) + '?v=' + versionId
}
