/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Condition, EvaluationResult, FieldDefinition, NormalizedValue, RuleDefinition } from './types.ts'

import { compare } from './compare.ts'
import { isInputType, operatorsFor, valueType } from './fieldTypes.ts'
import { evaluationOrder } from './graph.ts'
import { normalize } from './normalize.ts'

// Mirrors lib/Rules/RuleEngine.php. The server is authoritative: this version
// only gives live feedback in the browser. Semantics: docs/SCHEMA.md section 6.

type Effective = Map<string, NormalizedValue>

/**
 * Decide which fields are visible and required for the given values.
 *
 * @param definition form definition with `fields` and `rules`
 * @param values submitted values keyed by field ID
 * @return field IDs in document order
 * @throws RuleCycleError when the rules loop
 */
export function evaluateRules(definition: RuleDefinition, values: Record<string, unknown>): EvaluationResult {
	const fieldIds = Object.keys(definition.fields)
	const fields = new Map<string, FieldDefinition>(Object.entries(definition.fields))
	const rules = definition.rules ?? []
	const order = evaluationOrder(fieldIds, rules)

	// Conditions grouped per target field and effect.
	const conditions = new Map<string, Map<string, unknown[]>>()
	for (const rule of rules) {
		if (!Array.isArray(rule.targets)) {
			continue
		}
		for (const target of rule.targets) {
			if (typeof target !== 'string') {
				continue
			}
			const byEffect = conditions.get(target) ?? new Map<string, unknown[]>()
			const list = byEffect.get(String(rule.effect)) ?? []
			list.push(rule.when)
			byEffect.set(String(rule.effect), list)
			conditions.set(target, byEffect)
		}
	}

	const effective: Effective = new Map()
	const visible = new Set<string>()
	const required = new Set<string>()

	for (const id of order) {
		const field = fields.get(id) as FieldDefinition
		const type = valueType(field)
		const byEffect = conditions.get(id)
		const show = byEffect?.get('show') ?? []
		const hide = byEffect?.get('hide') ?? []
		const require = byEffect?.get('require') ?? []

		const isVisible = (show.length === 0 || anyMatches(show, fields, effective))
			&& !anyMatches(hide, fields, effective)

		if (!isVisible) {
			effective.set(id, null)
			continue
		}

		visible.add(id)
		if (isInputType(type)) {
			const raw = Object.hasOwn(values, id) ? values[id] : null
			effective.set(id, normalize(type, raw))
			if (field.required === true || anyMatches(require, fields, effective)) {
				required.add(id)
			}
		}
	}

	return {
		visible: fieldIds.filter((id) => visible.has(id)),
		required: fieldIds.filter((id) => required.has(id)),
	}
}

function anyMatches(conditions: unknown[], fields: Map<string, FieldDefinition>, effective: Effective): boolean {
	return conditions.some((condition) => matches(condition, fields, effective))
}

function matches(node: unknown, fields: Map<string, FieldDefinition>, effective: Effective): boolean {
	if (typeof node !== 'object' || node === null) {
		return false
	}
	const record = node as Record<string, unknown>

	if ('all' in record) {
		const members = (record as { all: unknown }).all
		return Array.isArray(members) && members.every((member) => matches(member, fields, effective))
	}

	if ('any' in record) {
		const members = (record as { any: unknown }).any
		return Array.isArray(members) && members.some((member) => matches(member, fields, effective))
	}

	const leaf = record as Partial<Exclude<Condition, { all: Condition[] } | { any: Condition[] }>>
	if (typeof leaf.field !== 'string' || typeof leaf.op !== 'string') {
		return false
	}
	const field = fields.get(leaf.field)
	if (field === undefined) {
		return false
	}
	const type = valueType(field)
	if (!operatorsFor(type).includes(leaf.op)) {
		return false
	}

	return compare(type, leaf.op, effective.get(leaf.field) ?? null, leaf.value ?? null)
}
