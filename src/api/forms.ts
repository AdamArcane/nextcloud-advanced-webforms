/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { BulkDeleteRequest, BulkStatusRequest, ExportOptions } from '../entries/exporting.ts'
import type { EntryFilterState } from '../entries/filter.ts'
import type { EntrySummaryData } from '../entries/summary.ts'
import type { DefinitionError, DeliveriesPage, Delivery, DeliveryStatus, DraftDefinition, EntriesPage, EntryDetail, EntryRecord, OwnerForm } from '../types.ts'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { bulkDeleteBody, bulkStatusBody, exportQuery } from '../entries/exporting.ts'
import { EMPTY_FILTER, filterParams } from '../entries/filter.ts'
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

/**
 * `baseRevision` is the revision the copy came from or was last saved at. Rejects with an ApiError of status
 * 409 and code `draft_conflict` when someone else saved since; its `data` is a {@link DraftConflict}.
 */
export function saveDraft(id: number, definition: DraftDefinition, baseRevision: number): Promise<SaveResult> {
	return call(axios.put<SaveResult>(url('/forms/' + id + '/draft'), { definition, baseRevision }))
}

/** The stored draft that a save ran into (docs/SCHEMA.md section 22.6). */
export interface DraftConflict {
	revision: number
	draft: DraftDefinition
	/** Display name of who saved it, empty when unknown. */
	savedBy: string
	savedAt: number
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

/** The shareable link to the public page, absolute. `token` is the form's token or its custom address. */
export function publicFormUrl(token: string): string {
	return window.location.origin + generateUrl('/apps/webforms/f/{token}', { token })
}

/** The page other websites put in a frame: the form without Nextcloud's header. */
export function embedFormUrl(token: string): string {
	return publicFormUrl(token) + '/embed'
}

export interface ShareOptions {
	/** The custom address, or null for none. */
	slug: string | null
	/** Whether other websites may show the form in a frame. */
	embed: boolean
	/** Websites allowed to do so. Empty means any. */
	embedOrigins: string[]
	/** A new password for the link. Left out, the password stays as it is. */
	password?: string
	/** Take the password off the link. */
	removePassword?: boolean
}

/** Rejects with an ApiError (status 422) whose `errors` say what is wrong: `slug_invalid`, `slug_reserved`, `slug_taken`, `embed_origins_invalid`, `password_too_short`, `password_too_long`. */
export function setFormShare(id: number, share: ShareOptions): Promise<OwnerForm> {
	return call(axios.put<OwnerForm>(url('/forms/' + id + '/share'), share))
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

export function listEntries(id: number, limit: number, offset: number, filter: EntryFilterState = EMPTY_FILTER): Promise<EntriesPage> {
	return call(axios.get<EntriesPage>(url('/forms/' + id + '/entries'), { params: { limit, offset, ...filterParams(filter) } }))
}

/** What the matching responses add up to. The order of a filter does not matter here. */
export function fetchEntrySummary(id: number, filter: EntryFilterState = EMPTY_FILTER): Promise<EntrySummaryData> {
	const { sort: _sort, ...params } = filterParams(filter)
	return call(axios.get<EntrySummaryData>(url('/forms/' + id + '/entries/summary'), { params }))
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

/** Where a download of responses lives. Opened as a link, so the session cookie authenticates it. */
export function exportUrl(id: number, options: ExportOptions = {}): string {
	const query = exportQuery(options)
	return generateUrl('/apps/webforms/api/v1/forms/{id}/entries/export', { id }) + (query === '' ? '' : '?' + query)
}

/** Delete the ticked responses, or everything a filter matches. Resolves to how many were deleted. */
export async function bulkDeleteEntries(id: number, request: BulkDeleteRequest): Promise<number> {
	return (await call(axios.post<{ deleted: number }>(url('/forms/' + id + '/entries/bulk-delete'), bulkDeleteBody(request)))).deleted
}

/** One response, with the definition it answered and its neighbours. Rejects with status 404 when it is gone. */
export function getEntry(id: number, entryId: number): Promise<EntryDetail> {
	return call(axios.get<EntryDetail>(url('/forms/' + id + '/entries/' + entryId)))
}

/**
 * Tell the server the owner is looking at the list of responses, so only later ones are new from now on.
 * Resolves to the moment before this visit (unix seconds), or null when the list was never opened.
 */
export async function markEntriesSeen(id: number): Promise<number | null> {
	return (await call(axios.post<{ previousSeenAt: number | null }>(url('/forms/' + id + '/entries/seen')))).previousSeenAt
}

/** Give a response a status, or null for none. Resolves to the response. */
export function setEntryStatus(id: number, entryId: number, status: string | null): Promise<EntryRecord> {
	return call(axios.put<EntryRecord>(url('/forms/' + id + '/entries/' + entryId + '/status'), { status }))
}

/** Save the private note on a response. Empty text removes it. Resolves to the response. */
export function setEntryNote(id: number, entryId: number, note: string): Promise<EntryRecord> {
	return call(axios.put<EntryRecord>(url('/forms/' + id + '/entries/' + entryId + '/note'), { note }))
}

/** Give the ticked responses, or everything a filter matches, a status. Resolves to how many changed. */
export async function bulkSetEntryStatus(id: number, request: BulkStatusRequest): Promise<number> {
	return (await call(axios.post<{ changed: number }>(url('/forms/' + id + '/entries/bulk-status'), bulkStatusBody(request)))).changed
}

export type ExportSource = 'draft' | 'published'

/** Where the form export download lives. Opened as a link, so the session cookie authenticates it. */
export function formExportUrl(id: number, source: ExportSource): string {
	return generateUrl('/apps/webforms/api/v1/forms/{id}/export', { id }) + '?source=' + source
}

/** Where the zip of every form the user owns is downloaded (docs/SCHEMA.md section 13.1). Opened as a link. */
export function formsBackupUrl(): string {
	return generateUrl('/apps/webforms/api/v1/backup')
}

export interface Collaborator {
	id: number
	type: 'user' | 'group'
	/** The account or group id. */
	with: string
	displayName: string
	role: 'editor' | 'reviewer'
	createdAt: number
}

export interface Sharee {
	type: 'user' | 'group'
	with: string
	label: string
}

/** Who a form is shared with. Owner only. */
export async function listCollaborators(id: number): Promise<{ owner: { id: string, displayName: string }, shares: Collaborator[] }> {
	return call(axios.get(url('/forms/' + id + '/shares')))
}

/** Share with an account or group, or change the role of a share. Rejects with an ApiError (422): `share_target_invalid`, `share_is_owner`. */
export function shareForm(id: number, type: 'user' | 'group', withWhom: string, role: 'editor' | 'reviewer'): Promise<Collaborator> {
	return call(axios.put<Collaborator>(url('/forms/' + id + '/shares'), { type, with: withWhom, role }))
}

export async function unshareForm(id: number, shareId: number): Promise<void> {
	await call(axios.delete(url('/forms/' + id + '/shares/' + shareId)))
}

/** The person gives up their own access to a form shared with them. */
export async function leaveForm(id: number): Promise<void> {
	await call(axios.delete(url('/forms/' + id + '/shares/me')))
}

/**
 * The owner hands the form to another account and stays on as an editor (docs/SCHEMA.md section 22.7). Rejects
 * with an ApiError (422): `transfer_target_invalid`, `transfer_destinations_unavailable` (the places, as
 * `path`) or `transfer_has_files`.
 */
export function transferForm(id: number, to: string): Promise<OwnerForm> {
	return call(axios.post<OwnerForm>(url('/forms/' + id + '/transfer'), { to }))
}

/** Accounts and groups to share with, found by what was typed. */
export async function findSharees(id: number, search: string): Promise<Sharee[]> {
	return (await call(axios.get<{ sharees: Sharee[] }>(url('/forms/' + id + '/sharees'), { params: { search } }))).sharees
}

/** A new draft with the same content as this form's draft. Rejects with an ApiError. */
export function duplicateForm(id: number, title: string): Promise<SaveResult> {
	return call(axios.post<SaveResult>(url('/forms/' + id + '/duplicate'), { title }))
}

/** Create a new draft form from the parsed contents of an export file. Rejects with an ApiError. */
export function importForm(pkg: unknown): Promise<SaveResult> {
	return call(axios.post<SaveResult>(url('/forms/import'), { package: pkg }))
}

/** A form in the core Forms app that the person owns there. */
export interface CoreFormSummary {
	id: number
	title: string
	responses: number
}

/** One thing that did not come across from core Forms (docs/SCHEMA.md section 13.3). */
export interface CoreImportSkipped {
	scope: 'form' | 'question' | 'answer'
	ref: string
	/** The question's text, for a question or answer line. */
	label: string
	code: string
	/** An English sentence, only for a code the screen has no text for. */
	detail: string
	/** For an answer line, how many answers it stands for. */
	count: number
}

export interface CoreImportOutcome {
	coreId: number
	title: string
	/** The new draft, or null for a preview. */
	formId: number | null
	fields: number
	responsesFound: number
	responsesImported: number
	errors: DefinitionError[]
	skipped: CoreImportSkipped[]
}

/** The forms the person owns in the core Forms app, or `available: false` when it was never installed. */
export function fetchCoreForms(): Promise<{ available: boolean, forms: CoreFormSummary[] }> {
	return call(axios.get<{ available: boolean, forms: CoreFormSummary[] }>(url('/import/core-forms')))
}

/** Import one core Forms form as a new draft, or with `dryRun` only report what would be left out. Rejects with an ApiError. */
export function importCoreForm(formId: number, withResponses: boolean, dryRun: boolean): Promise<CoreImportOutcome> {
	return call(axios.post<CoreImportOutcome>(url('/import/core-forms'), { formId, withResponses, dryRun }))
}
