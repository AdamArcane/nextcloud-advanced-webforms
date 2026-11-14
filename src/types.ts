/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LayoutPage } from './layout/visibleLayout.ts'
import type { Condition, Rule } from './rules/types.ts'

/** Where a field's label sits (docs/SCHEMA.md 4.2). */
export type LabelPosition = 'above' | 'inline' | 'hidden'

export interface FieldOption {
	value: string
	label: string
}

/** A field in a form definition (docs/SCHEMA.md section 4). Type alias, so it also fits the rule engine's index-signature type. */
export type FormField = {
	type: string
	label?: string
	description?: string
	required?: boolean
	defaultValue?: unknown
	/** Older flag, the same as `labelPosition: 'hidden'`. */
	hideLabel?: boolean
	labelPosition?: LabelPosition
	placeholder?: string
	maxLength?: number
	min?: number
	max?: number
	step?: number
	options?: FieldOption[]
	minSelected?: number
	maxSelected?: number
	text?: string
	level?: number
	/** Text alignment of a heading or paragraph. Default `start`. */
	align?: 'start' | 'center' | 'end'
	/** Upload fields (docs/SCHEMA.md 4.6). */
	maxFiles?: number
	maxSizeMb?: number
	/** Groups (`images`, `documents`, `archives`, `audio`, `video`) and extra extensions like `.dwg`. */
	accept?: string[]
	audience?: 'anyone' | 'login'
	storage?: 'files' | 'app'
	/** Folder in the owner's Files. Never sent to respondents. */
	folder?: string
}

/** One uploaded file as stored in a response. `ref` and `storage` stay on the server for respondents and receivers. */
export interface StoredFile {
	name: string
	size: number
	mime: string
	storage: 'files' | 'app'
	ref: string
}

/** How the public page looks (docs/SCHEMA.md 3). Every key is optional; unset means the default. */
export interface FormDesign {
	/** Width of the form column. Default `standard`. */
	width?: 'narrow' | 'standard' | 'wide'
	/** Put the form on a card (default) or straight on the page. */
	card?: boolean
	/** `default` keeps Nextcloud's own page background. */
	background?: 'default' | 'plain' | 'color'
	/** `#rrggbb`, used when `background` is `color`. */
	backgroundColor?: string
	/** `#rrggbb`, used for highlights and, unless `buttonColor` is set, buttons. */
	accentColor?: string
	/** `#rrggbb` for the final submit button only. Defaults to the accent colour. */
	buttonColor?: string
}

export interface FormSettings {
	design?: FormDesign
	submitLabel?: string
	labelPosition?: LabelPosition
	requireLogin?: boolean
	allowMultipleSubmissions?: boolean
	/** Leave out the asterisk after required labels. Screen readers are still told. Default false. */
	hideRequiredIndicator?: boolean
	/** Leave the title out of the public page. It stays there for screen readers. Default false. */
	hideTitle?: boolean
	/** Ask anonymous respondents to pass the server's spam check. Default true; false turns it off for this form. */
	captcha?: boolean
	closedMessage?: string
}

/** The public view of a definition: `actions` is never sent to respondents. */
export interface PublicDefinition {
	schemaVersion: number
	title: string
	description?: string | null
	settings?: FormSettings
	fields: Record<string, FormField>
	pages: LayoutPage[]
	rules?: Rule[]
}

export interface PublicForm {
	token: string
	versionId: number
	fillToken: string
	definition: PublicDefinition
	/** Whether the visitor has a Nextcloud session. Upload fields can be limited to signed-in users. */
	signedIn?: boolean
	/** The server's largest single upload in bytes. */
	uploadLimit?: number
	/** The spam check to show, or null when this respondent does not need one. */
	captcha?: CaptchaConfig | null
}

export interface CaptchaConfig {
	provider: 'builtin' | 'turnstile' | 'hcaptcha'
	/** Public key of the provider; empty for the built-in check. */
	siteKey: string
}

export interface SubmitResult {
	message: string | null
	redirect: string | null
}

export interface FieldError {
	field?: string
	code: string
}

export type ActionType = 'message' | 'redirect' | 'email' | 'webhook'

interface ActionBase {
	/** Stable id, unique in the form. */
	id: string
	/** Only run when this holds for the submitted answers (docs/SCHEMA.md 7.1). */
	when?: Condition
}

export interface MessageAction extends ActionBase {
	type: 'message'
	text: string
}

export interface RedirectAction extends ActionBase {
	type: 'redirect'
	url: string
}

export interface EmailAction extends ActionBase {
	type: 'email'
	/**
	 * A fixed address, the answer to an email field, the form owner's account email, or the
	 * signed-in respondent's account email. The last two are looked up when the email is sent.
	 */
	to: string | { field: string } | { owner: true } | { respondent: true }
	subject: string
	body: string
}

export interface WebhookAction extends ActionBase {
	type: 'webhook'
	url: string
	/** Names the signing secret kept on the server. The secret itself is never in the definition. */
	secretRef?: string
}

export type FormAction = MessageAction | RedirectAction | EmailAction | WebhookAction

/** The owner's editable definition (docs/SCHEMA.md section 3), including `actions`. */
export interface DraftDefinition {
	schemaVersion: number
	title: string
	description?: string | null
	settings: FormSettings
	fields: Record<string, FormField>
	pages: LayoutPage[]
	rules: Rule[]
	actions: FormAction[]
}

export const FORM_STATUS_UNPUBLISHED = 0
export const FORM_STATUS_PUBLISHED = 1
export const FORM_STATUS_CLOSED = 2

/** A form as the owner API returns it. `draft` is only present on single-form calls. */
export interface OwnerForm {
	id: number
	title: string
	description: string | null
	status: number
	publicToken: string | null
	latestVersionId: number | null
	createdAt: number
	updatedAt: number
	draft?: DraftDefinition
	hasUnpublishedChanges?: boolean
	/** Field ids that can no longer be used because a published field had them. */
	retiredIds?: string[]
}

/** A structural problem the server found in a draft (`path` like `fields.f_abc.label`). */
export interface DefinitionError {
	code: string
	path: string
}

/** The part of a stored version an entry is read through. */
export interface VersionDefinition {
	fields: Record<string, FormField>
	pages: LayoutPage[]
}

export interface EntryRecord {
	id: number
	versionId: number
	userId: string | null
	/** Unix seconds. */
	submittedAt: number
	data: Record<string, unknown>
}

export interface EntriesPage {
	total: number
	entries: EntryRecord[]
	/** Definitions keyed by version id, for the versions the listed entries belong to. */
	versions: Record<string, VersionDefinition>
}

export type DeliveryStatus = 'queued' | 'success' | 'failed' | 'skipped'

/** One email or webhook delivery for one response. */
export interface Delivery {
	id: number
	entryId: number
	actionId: string
	type: string
	/** An address, a field label, or a webhook host. Never a full URL. */
	target: string | null
	status: DeliveryStatus
	attempt: number
	error: string | null
	createdAt: number
	updatedAt: number | null
	nextAttemptAt: number | null
}

export interface DeliveriesPage {
	total: number
	rows: Delivery[]
}
