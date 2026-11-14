/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormField } from './types.ts'

// Mirrors lib/Forms/UploadPolicy.php. The server decides; this only gives early feedback and the
// `accept` hint for the file picker.

export const UPLOAD_GROUPS: Record<string, readonly string[]> = {
	images: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'bmp', 'tif', 'tiff'],
	documents: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'rtf', 'csv', 'md'],
	archives: ['zip', '7z', 'tar', 'gz'],
	audio: ['mp3', 'wav', 'ogg', 'm4a', 'flac'],
	video: ['mp4', 'mov', 'webm', 'mkv', 'avi'],
}

export const DENIED_EXTENSIONS: readonly string[] = [
	'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps',
	'exe', 'dll', 'msi', 'com', 'scr', 'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe', 'wsf', 'hta', 'cpl', 'jar',
	'sh', 'bash', 'zsh', 'py', 'pl', 'rb', 'cgi', 'js', 'mjs', 'htm', 'html', 'xhtml', 'svg', 'swf',
	'htaccess', 'htpasswd', 'user.ini',
]

export const DEFAULT_ACCEPT = ['images', 'documents']
export const DEFAULT_MAX_FILES = 1
export const DEFAULT_MAX_SIZE_MB = 10

export function maxFiles(field: FormField): number {
	return field.maxFiles ?? DEFAULT_MAX_FILES
}

export function maxBytes(field: FormField, serverLimit?: number): number {
	const own = (field.maxSizeMb ?? DEFAULT_MAX_SIZE_MB) * 1024 * 1024
	return serverLimit && serverLimit > 0 ? Math.min(own, serverLimit) : own
}

export function allowedExtensions(field: FormField): string[] {
	const accept = field.accept && field.accept.length > 0 ? field.accept : DEFAULT_ACCEPT
	const extensions = new Set<string>()
	for (const entry of accept) {
		if (UPLOAD_GROUPS[entry]) {
			UPLOAD_GROUPS[entry].forEach((extension) => extensions.add(extension))
		} else if (/^\.[a-z0-9]{1,10}$/.test(entry)) {
			extensions.add(entry.slice(1))
		}
	}
	return [...extensions].filter((extension) => !DENIED_EXTENSIONS.includes(extension))
}

/** For the file picker's `accept` attribute. */
export function acceptAttribute(field: FormField): string {
	return allowedExtensions(field).map((extension) => '.' + extension).join(',')
}

export function extensionOf(name: string): string {
	const dot = name.lastIndexOf('.')
	return dot === -1 ? '' : name.slice(dot + 1).toLowerCase()
}

/** A server error code for a file the browser can already tell will be refused, or null. */
export function localProblem(field: FormField, file: { name: string, size: number }, serverLimit?: number): string | null {
	if (file.size < 1) {
		return 'upload_empty'
	}
	if (file.size > maxBytes(field, serverLimit)) {
		return 'upload_too_large'
	}
	if (!allowedExtensions(field).includes(extensionOf(file.name))) {
		return 'upload_type_not_allowed'
	}
	return null
}

export function formatSize(bytes: number): string {
	if (bytes < 1024) {
		return bytes + ' B'
	}
	if (bytes < 1024 * 1024) {
		return Math.round(bytes / 1024) + ' KB'
	}
	const megabytes = bytes / (1024 * 1024)
	return (Number.isInteger(megabytes) || megabytes >= 10 ? Math.round(megabytes) : megabytes.toFixed(1)) + ' MB'
}
