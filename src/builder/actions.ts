/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Pure helpers for editing after-submit actions (docs/SCHEMA.md section 7).

import type { ActionType, DraftDefinition, FormAction } from '../types.ts'
import type { AllCondition, AnyCondition } from '../rules/types.ts'

import { randomId } from './definition.ts'
import { defaultLeaf, sourceFields } from './rules.ts'

/** The server allows this many actions (DefinitionValidator::MAX_ACTIONS). */
export const MAX_ACTIONS = 10

export const ACTION_TYPES: readonly ActionType[] = ['message', 'redirect', 'email', 'webhook', 'tables', 'files']

/**
 * Where a new Files action writes, `Webforms/<form title> responses.csv`, with the title made safe as a
 * file name (no slashes, control characters or backslashes, not too long).
 */
export function defaultResponsePath(title: string): string {
	// eslint-disable-next-line no-control-regex
	const name = title.replace(/[\u0000-\u001f\u007f/\\]+/g, ' ').replace(/\s+/g, ' ').trim().replace(/^\.+/, '').slice(0, 80).trim()
	return 'Webforms/' + (name === '' ? 'Form' : name) + ' responses.csv'
}

/**
 * A path as the server accepts it (CsvFile::cleanPath): plain names joined by `/`, ending in `.csv`.
 * Leading and trailing slashes are dropped. Null when it cannot be used.
 */
export function cleanResponsePath(path: string): string | null {
	const trimmed = path.replace(/^[\s/]+|[\s/]+$/g, '')
	const parts = trimmed.split('/')
	// eslint-disable-next-line no-control-regex
	if (trimmed === '' || new TextEncoder().encode(trimmed).length > 255 || parts.length > 8 || parts.some((part) => part === '' || part === '.' || part === '..' || part !== part.trim() || /[\u0000-\u001f\u007f\\]/.test(part))) {
		return null
	}
	const name = parts[parts.length - 1]
	return name.length > 4 && name.toLowerCase().endsWith('.csv') ? trimmed : null
}

/** Message and redirect share one slot: the first that matches is the one shown. */
export function usesResponseSlot(action: FormAction): boolean {
	return action.type === 'message' || action.type === 'redirect'
}

/** Email fields, the only ones whose answer may be used as a recipient. */
export function emailFields(definition: DraftDefinition): { id: string, label: string }[] {
	return sourceFields(definition)
		.filter(({ field }) => field.type === 'email')
		.map(({ id, field }) => ({ id, label: field.label || '' }))
}

/** A new action with sensible starting content, or the form unchanged when it is full. */
export function addAction(definition: DraftDefinition, type: ActionType): { definition: DraftDefinition, actionId: string | null } {
	if (definition.actions.length >= MAX_ACTIONS) {
		return { definition, actionId: null }
	}
	const taken = new Set(definition.actions.map((action) => action.id))
	const id = randomId('act', taken)
	let action: FormAction
	switch (type) {
		case 'message':
			action = { id, type, text: 'Thanks, your response has been sent.' }
			break
		case 'redirect':
			action = { id, type, url: 'https://' }
			break
		case 'email': {
			const first = emailFields(definition)[0]
			action = {
				id,
				type,
				to: first ? { field: first.id } : '',
				subject: 'We received your response',
				body: 'Thanks for your response. We will be in touch soon.',
			}
			break
		}
		case 'tables':
			action = { id, type, tableId: 0, map: {} }
			break
		case 'files':
			action = { id, type, path: defaultResponsePath(definition.title) }
			break
		default:
			action = { id, type: 'webhook', url: 'https://' }
	}
	return { definition: { ...definition, actions: [...definition.actions, action] }, actionId: id }
}

export function updateAction(definition: DraftDefinition, index: number, action: FormAction): DraftDefinition {
	if (index < 0 || index >= definition.actions.length) {
		return definition
	}
	return { ...definition, actions: definition.actions.map((existing, i) => (i === index ? action : existing)) }
}

export function removeAction(definition: DraftDefinition, index: number): DraftDefinition {
	return { ...definition, actions: definition.actions.filter((_, i) => i !== index) }
}

/** Move an action up (-1) or down (+1). Order matters: the first matching message or redirect wins. */
export function moveAction(definition: DraftDefinition, index: number, by: -1 | 1): DraftDefinition {
	const target = index + by
	if (index < 0 || index >= definition.actions.length || target < 0 || target >= definition.actions.length) {
		return definition
	}
	const actions = [...definition.actions]
	;[actions[index], actions[target]] = [actions[target], actions[index]]
	return { ...definition, actions }
}

/** A starting condition for "only when…", or null if no input field exists to read. */
export function defaultWhen(definition: DraftDefinition): AllCondition | null {
	const first = sourceFields(definition)[0]
	return first ? { all: [defaultLeaf(definition, first.id)] } : null
}

/** Set or clear an action's condition. */
export function setWhen(action: FormAction, when: AllCondition | AnyCondition | null): FormAction {
	const { when: _previous, ...rest } = action
	return (when === null ? rest : { ...rest, when }) as FormAction
}

/** `text` with `{fieldId}` added at the end, separated by a space when needed. */
export function withPlaceholder(text: string, fieldId: string): string {
	const gap = text === '' || /\s$/.test(text) ? '' : ' '
	return text + gap + '{' + fieldId + '}'
}

/**
 * Message and redirect actions that always apply (no condition) after the
 * first one can never be shown, because the first match wins. Returns their ids.
 */
export function unreachableResponseActions(definition: DraftDefinition): string[] {
	const unreachable: string[] = []
	let slotAlwaysTaken = false
	for (const action of definition.actions) {
		if (!usesResponseSlot(action)) {
			continue
		}
		if (slotAlwaysTaken) {
			unreachable.push(action.id)
		} else if (action.when === undefined) {
			slotAlwaysTaken = true
		}
	}
	return unreachable
}

/** The action index an error path like `actions[2].url` points into. */
export function errorActionIndex(path: string): number | null {
	const match = /^actions\[(\d+)\]/.exec(path)
	return match ? Number(match[1]) : null
}

/** In an email body, `{all_fields}` stands for every answer the respondent gave (docs/SCHEMA.md section 7.2). */
export const ALL_FIELDS = 'all_fields'

/** `text` with the list of all answers added on a line of its own at the end. */
export function withAllFields(text: string): string {
	const trimmed = text.replace(/\s+$/, '')
	return (trimmed === '' ? '' : trimmed + '\n\n') + '{' + ALL_FIELDS + '}'
}

/**
 * Placeholders in a text that name a field the form no longer has. `allFields` says the text is an
 * email body, where `{all_fields}` is not a field but still means something.
 */
export function unknownPlaceholders(text: string, definition: DraftDefinition, allFields: boolean = false): string[] {
	const unknown: string[] = []
	for (const match of text.matchAll(/\{([^{}]*)\}/g)) {
		if (allFields && match[1] === ALL_FIELDS) {
			continue
		}
		if (!(match[1] in definition.fields) && !unknown.includes(match[1])) {
			unknown.push(match[1])
		}
	}
	return unknown
}

/**
 * `text` with each `{fieldId}` shown as `{Field label}`, for summaries. Display
 * only: the stored text keeps the ids, because labels can change. Unknown ids
 * are left as they are so a broken placeholder stays visible.
 */
export function readablePlaceholders(text: string, definition: DraftDefinition): string {
	return text.replace(/\{([^{}]*)\}/g, (whole, id: string) => {
		const field = definition.fields[id]
		const label = field?.label || field?.text
		return label ? '{' + label + '}' : whole
	})
}
