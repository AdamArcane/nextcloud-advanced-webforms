/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Changing a field's id (docs/SCHEMA.md section 4.1). The id appears in webhook
// payloads and exports, so owners may choose it. Everything that refers to it has
// to move with it, or the form would quietly break.

import type { DraftDefinition, FormAction } from '../types.ts'
import type { Condition } from '../rules/types.ts'

import { cloneJson } from './definition.ts'

/** Same pattern as lib/Forms/DefinitionValidator.php. */
export const FIELD_ID_PATTERN = /^[a-z][a-z0-9_]{2,31}$/

export type FieldIdProblem =
	/** Unchanged. */
	| 'same'
	/** Not 3 to 32 lowercase letters, digits or underscores, starting with a letter. */
	| 'pattern'
	/** Another field in this form already has it. */
	| 'taken'
	/** A published field used it before, so it cannot mean something else now. */
	| 'retired'

/** Why `next` cannot be this field's new id, or null when it can. */
export function fieldIdProblem(
	definition: DraftDefinition,
	current: string,
	next: string,
	retired: readonly string[],
): FieldIdProblem | null {
	if (next === current) {
		return 'same'
	}
	if (!FIELD_ID_PATTERN.test(next)) {
		return 'pattern'
	}
	if (next in definition.fields) {
		return 'taken'
	}
	return retired.includes(next) ? 'retired' : null
}

function renameInCondition(condition: Condition, from: string, to: string): Condition {
	if ('field' in condition) {
		return condition.field === from ? { ...condition, field: to } : condition
	}
	if ('all' in condition) {
		return { all: condition.all.map((child) => renameInCondition(child, from, to)) }
	}
	return { any: condition.any.map((child) => renameInCondition(child, from, to)) }
}

/** `{from}` becomes `{to}` in text. Ids contain only [a-z0-9_], so they are safe to put in a pattern. */
function renameInText(text: string, from: string, to: string): string {
	return text.split('{' + from + '}').join('{' + to + '}')
}

function renameInAction(action: FormAction, from: string, to: string): FormAction {
	const renamed = { ...action } as FormAction
	if (action.when) {
		renamed.when = renameInCondition(action.when, from, to)
	}
	switch (renamed.type) {
		case 'message':
			renamed.text = renameInText(renamed.text, from, to)
			break
		case 'redirect':
			renamed.url = renameInText(renamed.url, from, to)
			break
		case 'email':
			renamed.subject = renameInText(renamed.subject, from, to)
			renamed.body = renameInText(renamed.body, from, to)
			if (typeof renamed.to === 'object' && 'field' in renamed.to && renamed.to.field === from) {
				renamed.to = { field: to }
			}
			if (typeof renamed.replyTo === 'object' && 'field' in renamed.replyTo && renamed.replyTo.field === from) {
				renamed.replyTo = { field: to }
			}
			break
		case 'tables':
			// The map is column id to source: the sources are what point at a field.
			renamed.map = Object.fromEntries(Object.entries(renamed.map).map(([column, source]) => [column, source === from ? to : source]))
			break
	}
	return renamed
}

/**
 * Give a field a new id and update everything that points at the old one: its
 * layout cell, rule targets and conditions, action conditions, an email's
 * recipient field, and `{placeholders}` in action text. Returns the definition
 * unchanged if the id is not valid (see fieldIdProblem for the reason).
 */
export function renameField(
	definition: DraftDefinition,
	current: string,
	next: string,
	retired: readonly string[] = [],
): DraftDefinition {
	if (!(current in definition.fields) || fieldIdProblem(definition, current, next, retired) !== null) {
		return definition
	}
	const result = cloneJson(definition)

	// Rebuild the map so the field keeps its position.
	result.fields = Object.fromEntries(Object.entries(result.fields).map(([id, field]) => [id === current ? next : id, field]))

	for (const page of result.pages) {
		for (const row of page.rows) {
			for (const cell of row.cells) {
				if (cell.field === current) {
					cell.field = next
				}
			}
		}
	}

	result.rules = result.rules.map((rule) => ({
		...rule,
		targets: rule.targets.map((target) => (target === current ? next : target)),
		when: renameInCondition(rule.when, current, next),
	}))
	result.actions = result.actions.map((action) => renameInAction(action, current, next))
	return result
}

// ---- Ids that follow the label ------------------------------------------------

/** Field types that carry a value and a label, so an id made from the label means something. */
const NAMED_TYPES = new Set(['text', 'textarea', 'email', 'phone', 'url', 'number', 'select', 'radio', 'checkbox', 'checkboxes', 'date', 'time', 'datetime', 'rating', 'scale', 'consent', 'file', 'hidden', 'slider', 'matrix', 'color', 'daterange', 'name', 'address', 'signature'])

const RANDOM_ID = /^f_[a-z0-9]{6}$/

/** `First Name` becomes `f_first_name`. Null when the label has nothing usable in it. */
export function slugFromLabel(label: string): string | null {
	const words = label
		.normalize('NFKD')
		.replace(/\p{M}+/gu, '')
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '_')
		.replace(/^_+|_+$/g, '')
	if (words === '') {
		return null
	}
	return ('f_' + words).slice(0, 32).replace(/_+$/, '')
}

/** The first free id for a label: the slug, else the slug with _2, _3 and so on. */
export function idFromLabel(label: string, unavailable: ReadonlySet<string>): string | null {
	const slug = slugFromLabel(label)
	if (slug === null || slug.length < 3) {
		return null
	}
	for (let n = 1; n < 1000; n++) {
		const suffix = n === 1 ? '' : '_' + n
		const id = slug.slice(0, 32 - suffix.length).replace(/_+$/, '') + suffix
		if (!unavailable.has(id)) {
			return id
		}
	}
	return null
}

/** Whether `id` is one the builder made itself (random, or made from `label`), as opposed to one the owner chose. */
function isAutoId(id: string, label: string | undefined): boolean {
	if (RANDOM_ID.test(id)) {
		return true
	}
	const slug = slugFromLabel(label ?? '')
	if (slug === null) {
		return false
	}
	const base = slug.slice(0, 30).replace(/_+$/, '')
	return id === slug || id.startsWith(base) && /^_\d+$/.test(id.slice(base.length))
}

/**
 * Make a field's id follow its label, unless the owner chose the id themselves or the field is
 * `frozen` (already in a published version, where the id is what webhooks and exports see).
 * `previousLabel` is what the label was before this change. Everything pointing at the id moves with it.
 */
export function followLabel(
	definition: DraftDefinition,
	fieldId: string,
	previousLabel: string | undefined,
	retired: readonly string[],
	frozen: ReadonlySet<string>,
): { definition: DraftDefinition, fieldId: string } {
	const field = definition.fields[fieldId]
	const unchanged = { definition, fieldId }
	if (!field || frozen.has(fieldId) || !NAMED_TYPES.has(field.type) || !isAutoId(fieldId, previousLabel)) {
		return unchanged
	}
	const unavailable = new Set<string>([...Object.keys(definition.fields), ...retired])
	unavailable.delete(fieldId)
	const next = idFromLabel(field.label ?? '', unavailable)
	if (next === null || next === fieldId) {
		return unchanged
	}
	const renamed = renameField(definition, fieldId, next, retired)
	return renamed === definition ? unchanged : { definition: renamed, fieldId: next }
}
