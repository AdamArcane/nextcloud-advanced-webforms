/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Folder, OwnerForm } from './types.ts'

/** One section of the sidebar's list of forms: a folder, or the forms in none. */
export interface FormSection {
	/** Also the name under which the browser remembers whether it is open. */
	key: string
	/** The folder, or null for "My forms" and "Shared with me". */
	folder: Folder | null
	kind: 'folder' | 'mine' | 'shared'
	forms: OwnerForm[]
}

export function sortFolders(folders: Folder[]): Folder[] {
	return [...folders].sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }))
}

/** A form someone else owns is listed apart, under their name (docs/SCHEMA.md section 22.3). */
export function isShared(form: OwnerForm): boolean {
	return form.role !== undefined && form.role !== 'owner'
}

/**
 * The sections in the order the sidebar shows them (docs/SCHEMA.md section 24): folders by name, empty ones
 * too, then My forms, then Shared with me when it has any. A form whose folder is not in the list (they could
 * not be loaded) counts as being in none, so no form ever disappears.
 */
export function formSections(forms: OwnerForm[], folders: Folder[]): FormSection[] {
	const known = new Set(folders.map((folder) => folder.id))
	const filed = (form: OwnerForm): boolean => form.folderId != null && known.has(form.folderId)
	const sections: FormSection[] = sortFolders(folders).map((folder) => ({
		key: 'folder:' + folder.id,
		folder,
		kind: 'folder',
		forms: forms.filter((form) => form.folderId === folder.id),
	}))
	const loose = forms.filter((form) => !filed(form))
	sections.push({ key: 'mine', folder: null, kind: 'mine', forms: loose.filter((form) => !isShared(form)) })
	const shared = loose.filter(isShared)
	if (shared.length > 0) {
		sections.push({ key: 'shared', folder: null, kind: 'shared', forms: shared })
	}
	return sections
}
