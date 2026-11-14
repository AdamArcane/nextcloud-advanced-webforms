/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LayoutPage } from './layout/visibleLayout.ts'
import type { Rule } from './rules/types.ts'

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
}

export interface FormSettings {
	submitLabel?: string
	labelPosition?: LabelPosition
	requireLogin?: boolean
	allowMultipleSubmissions?: boolean
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
}

export interface SubmitResult {
	message: string | null
	redirect: string | null
}

export interface FieldError {
	field?: string
	code: string
}

/** The owner's editable definition (docs/SCHEMA.md section 3), including `actions`. */
export interface DraftDefinition {
	schemaVersion: number
	title: string
	description?: string | null
	settings: FormSettings
	fields: Record<string, FormField>
	pages: LayoutPage[]
	rules: Rule[]
	actions: unknown[]
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
}

/** A structural problem the server found in a draft (`path` like `fields.f_abc.label`). */
export interface DefinitionError {
	code: string
	path: string
}
