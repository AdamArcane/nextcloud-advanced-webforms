/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LayoutPage } from './layout/visibleLayout.ts'
import type { Rule } from './rules/types.ts'

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
	hideLabel?: boolean
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
