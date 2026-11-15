/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormField } from '../../types.ts'

import { computed, reactive } from 'vue'
import { ApiError, uploadPublicFile } from '../../api/public.ts'
import { localProblem, maxFiles } from '../../uploads.ts'
import { messageFor } from './messages.ts'
import { t } from '@nextcloud/l10n'

export interface UploadContext {
	token: string
	versionId: number
	fillToken: string
	signedIn: boolean
	/** The builder preview: files are listed but never sent. */
	preview: boolean
	/** The server's largest single upload, in bytes. */
	limit?: number
}

export interface UploadItem {
	key: number
	id: string | null
	name: string
	size: number
	progress: number
	status: 'uploading' | 'done' | 'error'
	error: string | null
}

function errorText(error: unknown): string {
	if (error instanceof ApiError) {
		if (error.status === 422 && error.errors[0]) {
			return messageFor(error.errors[0].code)
		}
		if (error.status === 429) {
			return t('webforms', 'Too many uploads. Please wait a moment and try again.')
		}
		if (error.status === 413) {
			return messageFor('upload_too_large')
		}
		if (error.status === 401) {
			return messageFor('upload_login_required')
		}
	}
	return messageFor('upload_failed')
}

/**
 * The files of every upload field in a form. It lives with the form, not with a field's component, so
 * going to another page and back keeps the list, and an upload that finishes while its page is hidden
 * still lands in the answer. `values` is the form's answers: each upload field's answer is kept as
 * the list of upload ids that are done.
 */
export function createUploadState(context: () => UploadContext, values: Record<string, unknown>) {
	const lists = reactive<Record<string, UploadItem[]>>({})
	const notices = reactive<Record<string, string | null>>({})
	let nextKey = 1

	function items(fieldId: string): UploadItem[] {
		return lists[fieldId] ?? []
	}

	function sync(fieldId: string) {
		values[fieldId] = items(fieldId).filter((item) => item.status === 'done' && item.id !== null).map((item) => item.id as string)
	}

	const busy = computed(() => Object.values(lists).some((list) => list.some((item) => item.status === 'uploading')))

	async function send(fieldId: string, item: UploadItem, file: File) {
		const ctx = context()
		if (ctx.preview) {
			item.id = 'preview' + item.key
			item.status = 'done'
			item.progress = 1
			sync(fieldId)
			return
		}
		try {
			const result = await uploadPublicFile(ctx.token, ctx, fieldId, file, (fraction) => {
				item.progress = fraction
			})
			item.id = result.id
			item.status = 'done'
			item.progress = 1
		} catch (error) {
			item.status = 'error'
			item.error = errorText(error)
		}
		sync(fieldId)
	}

	function add(fieldId: string, field: FormField, files: File[]) {
		notices[fieldId] = null
		const limit = maxFiles(field)
		const single = limit === 1
		// Re-read through the reactive object: assigning returns the plain array, which would not notify the view.
		lists[fieldId] ??= []
		const list = lists[fieldId]
		// One file per field replaces the previous one instead of refusing it.
		if (single && files.length > 0) {
			list.splice(0, list.length)
		}
		for (const file of files) {
			if (!single && list.filter((item) => item.status !== 'error').length >= limit) {
				notices[fieldId] = messageFor('too_many_files')
				break
			}
			const problem = localProblem(field, file, context().limit)
			const item: UploadItem = { key: nextKey++, id: null, name: file.name, size: file.size, progress: 0, status: problem ? 'error' : 'uploading', error: problem ? messageFor(problem) : null }
			list.push(item)
			if (!problem) {
				// Work on the reactive copy so progress reaches the view.
				void send(fieldId, list[list.length - 1], file)
			}
			if (single) {
				break
			}
		}
		sync(fieldId)
	}

	function remove(fieldId: string, item: UploadItem) {
		const list = lists[fieldId]
		const at = list ? list.indexOf(item) : -1
		if (list && at !== -1) {
			list.splice(at, 1)
		}
		notices[fieldId] = null
		sync(fieldId)
	}

	return { items, notice: (fieldId: string) => notices[fieldId] ?? null, add, remove, busy }
}

export type UploadState = ReturnType<typeof createUploadState>
