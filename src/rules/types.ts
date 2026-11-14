/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** A field in a form definition (docs/SCHEMA.md section 4). Only what rules need is typed. */
export interface FieldDefinition {
	type: string
	required?: boolean
	options?: { value: string, label?: string }[]
	[key: string]: unknown
}

export interface LeafCondition {
	field: string
	op: string
	value?: unknown
}

export interface AllCondition {
	all: Condition[]
}

export interface AnyCondition {
	any: Condition[]
}

export type Condition = LeafCondition | AllCondition | AnyCondition

export type RuleEffect = 'show' | 'hide' | 'require'

export interface Rule {
	id: string
	effect: RuleEffect
	targets: string[]
	when: Condition
}

/** The part of a form definition the rule engine reads. */
export interface RuleDefinition {
	fields: Record<string, FieldDefinition>
	rules?: Rule[]
}

/** Field IDs in document order. */
export interface EvaluationResult {
	visible: string[]
	required: string[]
}

/** A value after normalization, null meaning empty. */
export type NormalizedValue = string | number | boolean | string[] | null
