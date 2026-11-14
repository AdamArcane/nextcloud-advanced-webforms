/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Pure helpers behind the rules editor. They keep every condition the UI
// builds consistent with lib/Rules/RuleValidator.php: operators allowed for the
// field type, a value shaped for the operator, and no dependency cycles.

import type { DraftDefinition, FormField } from '../types.ts'
import type { AllCondition, AnyCondition, Condition, LeafCondition, Rule, RuleEffect } from '../rules/types.ts'

import { isInputType, operatorsFor } from '../rules/fieldTypes.ts'
import { sourcesOf } from '../rules/graph.ts'
import { fieldOrder, randomId } from './definition.ts'

/** Deepest allowed group nesting (RuleValidator::MAX_GROUP_DEPTH). The root group is depth 1. */
export const MAX_GROUP_DEPTH = 5

export type ValueKind = 'none' | 'scalar' | 'list'

export function valueKind(op: string): ValueKind {
	if (op === 'empty' || op === 'not_empty') {
		return 'none'
	}
	return op === 'in' || op === 'not_in' ? 'list' : 'scalar'
}

export function isGroup(node: Condition): node is AllCondition | AnyCondition {
	return 'all' in node || 'any' in node
}

export function groupKind(node: AllCondition | AnyCondition): 'all' | 'any' {
	return 'all' in node ? 'all' : 'any'
}

export function groupChildren(node: AllCondition | AnyCondition): Condition[] {
	return 'all' in node ? node.all : node.any
}

export function makeGroup(kind: 'all' | 'any', children: Condition[]): AllCondition | AnyCondition {
	return kind === 'all' ? { all: children } : { any: children }
}

/** Effects a rule may have on a field: display fields cannot be required. */
export function effectsFor(field: FormField | undefined): RuleEffect[] {
	return field && isInputType(field.type) ? ['show', 'hide', 'require'] : ['show', 'hide']
}

export interface SourceField {
	id: string
	field: FormField
}

/** Input fields in layout order: the only fields a condition may read. */
export function sourceFields(definition: DraftDefinition): SourceField[] {
	return fieldOrder(definition)
		.filter((id) => definition.fields[id] && isInputType(definition.fields[id].type))
		.map((id) => ({ id, field: definition.fields[id] }))
}

/** The preferred operator when a field is first chosen. */
export function defaultOp(type: string): string {
	switch (type) {
		case 'select':
		case 'radio':
		case 'number':
		case 'checkbox':
			return 'eq'
		case 'checkboxes':
			return 'contains'
		default:
			return 'not_empty'
	}
}

function defaultScalar(field: FormField | undefined): unknown {
	switch (field?.type) {
		case 'select':
		case 'radio':
		case 'checkboxes':
			return field.options?.[0]?.value ?? ''
		case 'checkbox':
			return true
		case 'number':
			return 0
		default:
			return ''
	}
}

function leafFor(field: FormField | undefined, fieldId: string, op: string, previous?: unknown): LeafCondition {
	switch (valueKind(op)) {
		case 'none':
			return { field: fieldId, op }
		case 'list': {
			const list = Array.isArray(previous) ? previous : (previous !== undefined && previous !== '' ? [previous] : [defaultScalar(field)])
			return { field: fieldId, op, value: list }
		}
		default: {
			const scalar = Array.isArray(previous) ? previous[0] : previous
			return { field: fieldId, op, value: scalar === undefined ? defaultScalar(field) : scalar }
		}
	}
}

/** A fresh condition on a field. */
export function defaultLeaf(definition: DraftDefinition, fieldId: string): LeafCondition {
	const field = definition.fields[fieldId]
	return leafFor(field, fieldId, defaultOp(field?.type ?? ''))
}

/** Switch a condition to another field; the operator and value no longer apply, so they reset. */
export function changeLeafField(definition: DraftDefinition, fieldId: string): LeafCondition {
	return defaultLeaf(definition, fieldId)
}

/** Switch operator, carrying the value across where it still makes sense. */
export function changeLeafOp(definition: DraftDefinition, leaf: LeafCondition, op: string): LeafCondition {
	const field = definition.fields[leaf.field]
	if (!operatorsFor(field?.type ?? '').includes(op)) {
		return leaf
	}
	return leafFor(field, leaf.field, op, leaf.value)
}

/**
 * Fields a rule must not read, because reading them would make a loop: its own
 * targets, and anything whose visibility already depends on one of them.
 */
export function forbiddenSources(definition: DraftDefinition, rule: Pick<Rule, 'id' | 'targets'>): Set<string> {
	const dependents = new Map<string, Set<string>>()
	for (const other of definition.rules) {
		if (other.id === rule.id) {
			continue
		}
		for (const source of sourcesOf(other.when)) {
			const set = dependents.get(source) ?? new Set<string>()
			for (const target of other.targets) {
				set.add(target)
			}
			dependents.set(source, set)
		}
	}
	const forbidden = new Set<string>()
	const queue = [...rule.targets]
	while (queue.length > 0) {
		const id = queue.pop()!
		if (forbidden.has(id)) {
			continue
		}
		forbidden.add(id)
		queue.push(...(dependents.get(id) ?? []))
	}
	return forbidden
}

/** A new rule on a field, or null when no field is available to read. */
export function newRule(definition: DraftDefinition, targetId: string): Rule | null {
	const probe = { id: '', targets: [targetId] }
	const forbidden = forbiddenSources(definition, probe)
	const source = sourceFields(definition).find((candidate) => !forbidden.has(candidate.id))
	if (!source) {
		return null
	}
	const taken = new Set(definition.rules.map((rule) => rule.id))
	return {
		id: randomId('rule', taken),
		effect: 'show',
		targets: [targetId],
		when: { all: [defaultLeaf(definition, source.id)] },
	}
}

/** Rules that act on a field, with their index in the form's rule list. */
export function rulesTargeting(definition: DraftDefinition, fieldId: string): { rule: Rule, index: number }[] {
	return definition.rules
		.map((rule, index) => ({ rule, index }))
		.filter(({ rule }) => rule.targets.includes(fieldId))
}

/** Fields whose visibility or required state depends on this field's answer. */
export function dependentsOf(definition: DraftDefinition, fieldId: string): string[] {
	const result: string[] = []
	for (const rule of definition.rules) {
		if (sourcesOf(rule.when).includes(fieldId)) {
			for (const target of rule.targets) {
				if (!result.includes(target)) {
					result.push(target)
				}
			}
		}
	}
	return result
}

/** The rule index an error path like `rules[2].when.all[0].op` points into. */
export function errorRuleIndex(path: string): number | null {
	const match = /^rules\[(\d+)\]/.exec(path)
	return match ? Number(match[1]) : null
}
