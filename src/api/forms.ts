/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DefinitionError, DraftDefinition, OwnerForm } from '../types.ts'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { toApiError, url } from './http.ts'

async function call<T>(request: Promise<{ data: T }>): Promise<T> {
	try {
		return (await request).data
	} catch (error) {
		throw toApiError(error)
	}
}

export async function listForms(): Promise<OwnerForm[]> {
	return (await call(axios.get<{ forms: OwnerForm[] }>(url('/forms')))).forms
}

export function getForm(id: number): Promise<OwnerForm> {
	return call(axios.get<OwnerForm>(url('/forms/' + id)))
}

export function createForm(title: string): Promise<OwnerForm> {
	return call(axios.post<OwnerForm>(url('/forms'), { title }))
}

export interface SaveResult {
	form: OwnerForm
	/** Structural problems that will block publishing. Not an error for a draft. */
	errors: DefinitionError[]
}

export function saveDraft(id: number, definition: DraftDefinition): Promise<SaveResult> {
	return call(axios.put<SaveResult>(url('/forms/' + id + '/draft'), { definition }))
}

/** Rejects with an ApiError (status 422) carrying `errors` when the draft is not valid. */
export function publishForm(id: number): Promise<OwnerForm> {
	return call(axios.post<OwnerForm>(url('/forms/' + id + '/publish')))
}

export function setFormStatus(id: number, status: number): Promise<OwnerForm> {
	return call(axios.put<OwnerForm>(url('/forms/' + id + '/status'), { status }))
}

export async function deleteForm(id: number): Promise<void> {
	await call(axios.delete(url('/forms/' + id)))
}

/** The shareable link to the public page, absolute. */
export function publicFormUrl(token: string): string {
	return window.location.origin + generateUrl('/apps/advancedforms/f/{token}', { token })
}
