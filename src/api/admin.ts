/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ResponseStatus } from '../types.ts'

import axios from '@nextcloud/axios'
import { toApiError, url } from './http.ts'

export type CaptchaProviderId = 'none' | 'builtin' | 'turnstile' | 'hcaptcha'

export interface CaptchaAdminSettings {
	provider: CaptchaProviderId
	siteKey: string
	/** Whether a secret is stored. The secret itself is never sent back. */
	secretSet: boolean
}

export async function fetchCaptchaSettings(): Promise<CaptchaAdminSettings> {
	try {
		return (await axios.get<CaptchaAdminSettings>(url('/admin/captcha'))).data
	} catch (error) {
		throw toApiError(error)
	}
}

export async function saveCaptchaSettings(body: { provider: CaptchaProviderId, siteKey: string, secret: string | null }): Promise<CaptchaAdminSettings> {
	try {
		return (await axios.put<CaptchaAdminSettings>(url('/admin/captcha'), body)).data
	} catch (error) {
		throw toApiError(error)
	}
}

export interface PolicyGroup {
	id: string
	displayName: string
	/** False when a saved creator group has since been deleted. */
	exists?: boolean
}

/** The server policy as the administrator sees it (docs/SCHEMA.md section 16). */
export interface PolicyAdminSettings {
	/** The action types forms may use. */
	actionTypes: string[]
	allActionTypes: string[]
	/** 0 keeps responses for ever. */
	retentionDays: number
	/** 0 means no limit. */
	maxResponses: number
	maxActions: number
	maxActionsCeiling: number
	creatorGroups: PolicyGroup[]
	/** Forms may be shared with accounts and groups (docs/SCHEMA.md section 22.8). */
	sharing: boolean
}

export interface PolicyInput {
	actionTypes: string[]
	retentionDays: number
	maxResponses: number
	maxActions: number
	creatorGroups: string[]
	sharing: boolean
	/** Agree to delete the responses that retention would remove right now. */
	confirmDelete?: boolean
}

export async function fetchPolicy(): Promise<PolicyAdminSettings> {
	try {
		return (await axios.get<PolicyAdminSettings>(url('/admin/policy'))).data
	} catch (error) {
		throw toApiError(error)
	}
}

/** Rejects with code `confirmation_required` and `data.due` when saving would delete existing responses. */
export async function savePolicy(input: PolicyInput): Promise<PolicyAdminSettings> {
	try {
		return (await axios.put<PolicyAdminSettings>(url('/admin/policy'), input)).data
	} catch (error) {
		throw toApiError(error)
	}
}

/** How many existing responses are older than this many days. */
export async function fetchRetentionPreview(days: number): Promise<number> {
	try {
		return (await axios.get<{ due: number }>(url('/admin/policy/retention-preview'), { params: { days } })).data.due
	} catch (error) {
		throw toApiError(error)
	}
}

export async function searchGroups(search: string): Promise<PolicyGroup[]> {
	try {
		return (await axios.get<{ groups: PolicyGroup[] }>(url('/admin/groups'), { params: { search } })).data.groups
	} catch (error) {
		throw toApiError(error)
	}
}

/** The settings for mail that forms send (docs/SCHEMA.md section 16.2). */
export interface MailAdminSettings {
	/** The default reply-to when an email action sets none: nothing, the form owner's account email, or `replyToAddress`. */
	replyToMode: 'none' | 'owner' | 'address'
	replyToAddress: string
	/** Whether form owners may set a reply-to and sender name of their own. */
	allowOverride: boolean
	/** The name mail is sent under. Empty keeps Nextcloud's own. */
	fromName: string
}

export async function fetchMailSettings(): Promise<MailAdminSettings> {
	try {
		return (await axios.get<MailAdminSettings>(url('/admin/mail'))).data
	} catch (error) {
		throw toApiError(error)
	}
}

/** Rejects with code `invalid_reply_to_address` or `invalid_from_name` when a value is refused. */
export async function saveMailSettings(input: MailAdminSettings): Promise<MailAdminSettings> {
	try {
		return (await axios.put<MailAdminSettings>(url('/admin/mail'), input)).data
	} catch (error) {
		throw toApiError(error)
	}
}

/** The list of response statuses and its limits (docs/SCHEMA.md section 20.2). */
export interface StatusAdminSettings {
	statuses: ResponseStatus[]
	colors: string[]
	max: number
	maxLabel: number
}

export async function fetchStatuses(): Promise<StatusAdminSettings> {
	try {
		return (await axios.get<StatusAdminSettings>(url('/admin/statuses'))).data
	} catch (error) {
		throw toApiError(error)
	}
}

/**
 * Rejects with code `confirmation_required` and `data.affected` when a status that was taken out is
 * still on responses, until `confirmDelete` is sent.
 */
export async function saveStatuses(statuses: ResponseStatus[], confirmDelete = false): Promise<StatusAdminSettings> {
	try {
		return (await axios.put<StatusAdminSettings>(url('/admin/statuses'), { statuses, confirmDelete })).data
	} catch (error) {
		throw toApiError(error)
	}
}
