/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Folder } from '../types.ts'

import axios from '@nextcloud/axios'
import { toApiError, url } from './http.ts'

// The caller's own folders (docs/SCHEMA.md section 24). Names the server refuses reject with an ApiError of
// status 422 whose error is `folder_name_invalid`, `folder_name_taken` or `folder_limit`.

async function call<T>(request: Promise<{ data: T }>): Promise<T> {
	try {
		return (await request).data
	} catch (error) {
		throw toApiError(error)
	}
}

export async function listFolders(): Promise<Folder[]> {
	return (await call(axios.get<{ folders: Folder[] }>(url('/folders')))).folders
}

export function createFolder(name: string): Promise<Folder> {
	return call(axios.post<Folder>(url('/folders'), { name }))
}

export function renameFolder(id: number, name: string): Promise<Folder> {
	return call(axios.put<Folder>(url('/folders/' + id), { name }))
}

/** The forms in it are not deleted. */
export async function deleteFolder(id: number): Promise<void> {
	await call(axios.delete(url('/folders/' + id)))
}

/** Put a form in a folder, or with null take it out. */
export async function setFormFolder(formId: number, folderId: number | null): Promise<void> {
	await call(axios.put(url('/forms/' + formId + '/folder'), { folderId }))
}
