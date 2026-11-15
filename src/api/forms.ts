/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DefinitionError, DeliveriesPage, Delivery, DeliveryStatus, DraftDefinition, EntriesPage, OwnerForm } from '../types.ts'

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

/** Archive a form, or bring it back. Archived forms stop taking responses. */
export function setFormArchived(id: number, archived: boolean): Promise<OwnerForm> {
	return call(axios.put<OwnerForm>(url('/forms/' + id + '/archived'), { archived }))
}

export async function deleteForm(id: number): Promise<void> {
	await call(axios.delete(url('/forms/' + id)))
}

/** The shareable link to the public page, absolute. */
export function publicFormUrl(token: string): string {
	return window.location.origin + generateUrl('/apps/webforms/f/{token}', { token })
}

export interface WebhookSecret {
	ref: string
	/** Shown once. The server keeps it encrypted and cannot return it again. */
	secret: string
}

/** Create a signing secret for a webhook, or replace the one under `ref`. */
export function createWebhookSecret(id: number, ref?: string): Promise<WebhookSecret> {
	return call(axios.post<WebhookSecret>(url('/forms/' + id + '/webhook-secret'), ref ? { ref } : {}))
}

export function listEntries(id: number, limit: number, offset: number): Promise<EntriesPage> {
	return call(axios.get<EntriesPage>(url('/forms/' + id + '/entries'), { params: { limit, offset } }))
}

export async function deleteEntry(id: number, entryId: number): Promise<void> {
	await call(axios.delete(url('/forms/' + id + '/entries/' + entryId)))
}

export interface DeliveryQuery {
	status?: DeliveryStatus
	entryId?: number
	limit?: number
	offset?: number
}

export function listDeliveries(id: number, query: DeliveryQuery = {}): Promise<DeliveriesPage> {
	return call(axios.get<DeliveriesPage>(url('/forms/' + id + '/deliveries'), { params: query }))
}

/** Try a failed delivery again from scratch. */
export function retryDelivery(id: number, logId: number): Promise<Delivery> {
	return call(axios.post<Delivery>(url('/forms/' + id + '/deliveries/' + logId + '/retry')))
}

/** Where the CSV download lives. Opened as a link, so the session cookie authenticates it. */
export function exportUrl(id: number): string {
	return generateUrl('/apps/webforms/api/v1/forms/{id}/entries/export', { id })
}

export type ExportSource = 'draft' | 'published'

/** Where the form export download lives. Opened as a link, so the session cookie authenticates it. */
export function formExportUrl(id: number, source: ExportSource): string {
	return generateUrl('/apps/webforms/api/v1/forms/{id}/export', { id }) + '?source=' + source
}

/** Create a new draft form from the parsed contents of an export file. Rejects with an ApiError. */
export function importForm(pkg: unknown): Promise<SaveResult> {
	return call(axios.post<SaveResult>(url('/forms/import'), { package: pkg }))
}
