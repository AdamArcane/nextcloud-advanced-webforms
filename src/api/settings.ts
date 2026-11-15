/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ActionType } from '../types.ts'

import axios from '@nextcloud/axios'
import { url } from './http.ts'

/** What the administrator's policy means for the signed-in user (docs/SCHEMA.md section 16). */
export interface UserPolicy {
	canCreate: boolean
	allowedActionTypes: ActionType[]
	maxActions: number
	mail: MailPolicy
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
}

export async function fetchUserPolicy(): Promise<UserPolicy> {
	try {
		const policy = (await axios.get<UserPolicy>(url('/settings'))).data
		return { ...policy, mail: { ...OPEN_MAIL_POLICY, ...policy.mail } }
	} catch {
		// The server still enforces the policy when a form is published, so showing everything is safe.
		return OPEN_POLICY
	}
}

/** The signed-in user's own preferences, set under Personal settings. */
export interface UserPreferences {
	/** Picking a published form with no unpublished changes opens its responses instead of the editor. */
	openPublishedOnResponses: boolean
}

export const DEFAULT_PREFERENCES: UserPreferences = {
	openPublishedOnResponses: true,
}

export async function fetchUserPreferences(): Promise<UserPreferences> {
	try {
		return { ...DEFAULT_PREFERENCES, ...(await axios.get<UserPreferences>(url('/preferences'))).data }
	} catch {
		return DEFAULT_PREFERENCES
	}
}

/** Rejects when the preferences could not be saved. */
export async function saveUserPreferences(preferences: UserPreferences): Promise<UserPreferences> {
	return (await axios.put<UserPreferences>(url('/preferences'), preferences)).data
}
