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
			if (typeof renamed.to === 'object' && renamed.to.field === from) {
				renamed.to = { field: to }
			}
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
