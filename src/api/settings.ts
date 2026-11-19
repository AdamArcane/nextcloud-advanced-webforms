/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ActionType, LabelPosition, ResponseStatus } from '../types.ts'

import axios from '@nextcloud/axios'
import { url } from './http.ts'

/** What the administrator's policy means for the signed-in user (docs/SCHEMA.md section 16). */
export interface UserPolicy {
	canCreate: boolean
	/** Forms may be shared with other accounts and groups (docs/SCHEMA.md section 22.8). Missing means they may. */
	canShare?: boolean
	allowedActionTypes: ActionType[]
	maxActions: number
	mail: MailPolicy
	/** The statuses a response can be given (docs/SCHEMA.md section 20.2). */
	statuses: ResponseStatus[]
}

/** What the administrator set for mail that forms send (docs/SCHEMA.md section 16.2). */
export interface MailPolicy {
	/** Whether an email action's own reply-to and sender name count. */
	allowOverride: boolean
	/** Where replies go when an action sets no reply-to. */
	replyToMode: 'none' | 'owner' | 'address'
	replyToAddress: string
	/** The default sender name. Empty means Nextcloud's own. */
	fromName: string
	/** The address a reply reaches when an action sets no reply-to, for a form this user owns. Empty when unknown. */
	defaultReplyTo: string
	/** The name mail is sent under when an action sets none. Empty when unknown. */
	defaultFromName: string
}

export const OPEN_MAIL_POLICY: MailPolicy = { allowOverride: true, replyToMode: 'none', replyToAddress: '', fromName: '', defaultReplyTo: '', defaultFromName: '' }

/** Used when the settings cannot be read: everything allowed, so a hiccup never hides the builder. */
export const OPEN_POLICY: UserPolicy = {
	canCreate: true,
	allowedActionTypes: ['message', 'redirect', 'email', 'webhook', 'tables', 'files'],
	maxActions: 10,
	mail: OPEN_MAIL_POLICY,
	statuses: [],
}

export async function fetchUserPolicy(): Promise<UserPolicy> {
	try {
		const policy = (await axios.get<UserPolicy>(url('/settings'))).data
		return { ...policy, mail: { ...OPEN_MAIL_POLICY, ...policy.mail }, statuses: Array.isArray(policy.statuses) ? policy.statuses : [] }
	} catch {
		// The server still enforces the policy when a form is published, so showing everything is safe.
		return OPEN_POLICY
	}
}

/** How the responses of a form are laid out. */
export type ResponsesView = 'table' | 'cards'

/** The signed-in user's own preferences, set in the app's Settings dialog (docs/SCHEMA.md section 16.1). */
export interface UserPreferences {
	/** Picking a published form with no unpublished changes opens its responses instead of the editor. */
	openPublishedOnResponses: boolean
	/** The starting label position of a form the user creates. */
	newFormLabelPosition: LabelPosition
	/** The starting accent colour (`#rrggbb`) of a new form. Empty means the Nextcloud colour. */
	newFormAccentColor: string
	/** New forms start with their title left off the public page. */
	newFormHideTitle: boolean
	/** New forms start with the spam check on. */
	newFormCaptcha: boolean
	/** Responses open as a table or as cards. */
	responsesView: ResponsesView
	/** The summary above the responses is open. */
	responsesSummary: boolean
}

export const DEFAULT_PREFERENCES: UserPreferences = {
	openPublishedOnResponses: true,
	newFormLabelPosition: 'above',
	newFormAccentColor: '',
	newFormHideTitle: false,
	newFormCaptcha: true,
	responsesView: 'table',
	responsesSummary: true,
}

export async function fetchUserPreferences(): Promise<UserPreferences> {
	try {
		return { ...DEFAULT_PREFERENCES, ...(await axios.get<UserPreferences>(url('/preferences'))).data }
	} catch {
		return DEFAULT_PREFERENCES
	}
}

/** Save some preferences and leave the rest. Resolves with all of them as the server keeps them; rejects when they could not be saved. */
export async function saveUserPreferences(patch: Partial<UserPreferences>): Promise<UserPreferences> {
	return { ...DEFAULT_PREFERENCES, ...(await axios.put<UserPreferences>(url('/preferences'), patch)).data }
}
