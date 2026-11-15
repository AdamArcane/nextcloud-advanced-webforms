/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
}

export interface PolicyInput {
	actionTypes: string[]
	retentionDays: number
	maxResponses: number
	maxActions: number
	creatorGroups: string[]
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
