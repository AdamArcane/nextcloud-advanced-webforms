/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Ref } from 'vue'
import type { Folder, OwnerForm } from './types.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import { deleteFolder, listFolders, setFormFolder } from './api/folders.ts'

/** What dragging a row of the list carries, so nothing else dropped on a folder is mistaken for a form. */
export const FORM_DRAG_TYPE = 'application/x-webforms-form'

/**
 * The person's folders and everything the list of forms does with them (docs/SCHEMA.md section 24).
 *
 * @param forms the list of forms, whose `folderId` this keeps in step
 */
export function useFolders(forms: Ref<OwnerForm[]>) {
	const folders = ref<Folder[]>([])
	const loadFailed = ref(false)

	// The folder dialog: closed, making a new one (`folder` null), or renaming one.
	const naming = ref<{ folder: Folder | null, thenMove: number | null } | null>(null)
	const moving = ref<OwnerForm | null>(null)
	const deleting = ref<Folder | null>(null)

	async function load(): Promise<void> {
		try {
			folders.value = await listFolders()
			loadFailed.value = false
		} catch {
			loadFailed.value = true
		}
	}

	function place(formId: number, folderId: number | null): void {
		forms.value = forms.value.map((form) => (form.id === formId ? { ...form, folderId } : form))
	}

	/** Shown at once, and put back if the server says no. */
	async function move(formId: number, folderId: number | null): Promise<void> {
		const form = forms.value.find((one) => one.id === formId)
		const previous = form?.folderId ?? null
		if (!form || previous === folderId) {
			return
		}
		place(formId, folderId)
		try {
			await setFormFolder(formId, folderId)
		} catch {
			place(formId, previous)
			showError(t('webforms', 'Could not move the form.'))
		}
	}

	function pick(folderId: number | null): void {
		const form = moving.value
		moving.value = null
		if (form) {
			void move(form.id, folderId)
		}
	}

	/** From the move dialog: name a new folder, then put the form in it. */
	function newFolderForMoving(): void {
		naming.value = { folder: null, thenMove: moving.value?.id ?? null }
		moving.value = null
	}

	function onSaved(saved: Folder): void {
		const thenMove = naming.value?.thenMove ?? null
		folders.value = [...folders.value.filter((folder) => folder.id !== saved.id), saved]
		if (thenMove !== null) {
			void move(thenMove, saved.id)
		}
	}

	async function confirmDelete(): Promise<void> {
		const folder = deleting.value
		deleting.value = null
		if (!folder) {
			return
		}
		try {
			await deleteFolder(folder.id)
			folders.value = folders.value.filter((one) => one.id !== folder.id)
			forms.value = forms.value.map((form) => (form.folderId === folder.id ? { ...form, folderId: null } : form))
		} catch {
			showError(t('webforms', 'Could not delete the folder.'))
		}
	}

	// Dragging a form onto a section, for a mouse. Touch screens use "Move to folder".
	const dragged = ref<number | null>(null)
	const dropTarget = ref<string | null>(null)

	function dragStart(event: DragEvent, form: OwnerForm): void {
		dragged.value = form.id
		event.dataTransfer?.setData(FORM_DRAG_TYPE, String(form.id))
	}

	function dragOver(event: DragEvent, section: string): void {
		if (dragged.value === null || !event.dataTransfer?.types.includes(FORM_DRAG_TYPE)) {
			return
		}
		event.preventDefault()
		dropTarget.value = section
	}

	function dragLeave(event: DragEvent): void {
		const inside = event.relatedTarget instanceof Node && (event.currentTarget as Node).contains(event.relatedTarget)
		if (!inside) {
			dropTarget.value = null
		}
	}

	function drop(event: DragEvent, folderId: number | null): void {
		const id = dragged.value
		dragEnd()
		if (id === null || !event.dataTransfer?.types.includes(FORM_DRAG_TYPE)) {
			return
		}
		event.preventDefault()
		void move(id, folderId)
	}

	function dragEnd(): void {
		dragged.value = null
		dropTarget.value = null
	}

	return { folders, loadFailed, load, naming, moving, deleting, move, pick, newFolderForMoving, onSaved, confirmDelete, dropTarget, dragStart, dragOver, dragLeave, drop, dragEnd }
}
