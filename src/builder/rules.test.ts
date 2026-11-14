/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DraftDefinition } from '../types.ts'

import { describe, expect, it } from 'vitest'
import { operatorsFor } from '../rules/fieldTypes.ts'
import { addField, emptyDefinition, updateField } from './definition.ts'
import {
	changeLeafField,
	changeLeafOp,
	defaultLeaf,
	dependentsOf,
	effectsFor,
	errorRuleIndex,
	forbiddenSources,
	newRule,
	rulesTargeting,
	sourceFields,
	valueKind,
} from './rules.ts'

function form(): { def: DraftDefinition, name: string, kind: string, url: string, tags: string, ok: string, age: string, note: string } {
	let def = emptyDefinition('T')
	const add = (type: string): string => {
		const result = addField(def, type, { kind: 'row', index: def.pages[0].rows.length })
		def = result.definition
		return result.fieldId
	}
	const name = add('text')
	const kind = add('select')
	const url = add('text')
	const tags = add('checkboxes')
	const ok = add('checkbox')
	const age = add('number')
	const note = add('paragraph')
	return { def, name, kind, url, tags, ok, age, note }
}

describe('valueKind', () => {
	it('classifies operators by the value they take', () => {
		expect(valueKind('empty')).toBe('none')
		expect(valueKind('not_empty')).toBe('none')
		expect(valueKind('in')).toBe('list')
		expect(valueKind('not_in')).toBe('list')
		expect(valueKind('contains_any')).toBe('list')
		expect(valueKind('contains_all')).toBe('list')
		expect(valueKind('contains_none')).toBe('list')
		expect(valueKind('eq')).toBe('scalar')
		expect(valueKind('gte')).toBe('scalar')
	})
})

describe('defaultLeaf', () => {
	it('picks an operator the field type allows and a value of the right shape', () => {
		const { def, name, kind, tags, ok, age } = form()
		expect(defaultLeaf(def, name)).toEqual({ field: name, op: 'not_empty' })
		const select = defaultLeaf(def, kind)
		expect(select).toMatchObject({ field: kind, op: 'eq' })
		expect(select.value).toBe(def.fields[kind].options![0].value)
		expect(defaultLeaf(def, tags)).toMatchObject({ op: 'contains', value: def.fields[tags].options![0].value })
		expect(defaultLeaf(def, ok)).toEqual({ field: ok, op: 'eq', value: true })
		expect(defaultLeaf(def, age)).toEqual({ field: age, op: 'eq', value: 0 })
	})

	it('only ever uses an operator valid for the type', () => {
		const { def, name, kind, tags, ok, age } = form()
		for (const id of [name, kind, tags, ok, age]) {
			expect(operatorsFor(def.fields[id].type)).toContain(defaultLeaf(def, id).op)
		}
	})
})

describe('changeLeafOp', () => {
	it('drops the value for empty checks and restores a default when it comes back', () => {
		const { def, kind } = form()
		const eq = defaultLeaf(def, kind)
		const empty = changeLeafOp(def, eq, 'empty')
		expect('value' in empty).toBe(false)
		expect(changeLeafOp(def, empty, 'neq')).toMatchObject({ op: 'neq', value: def.fields[kind].options![0].value })
	})

	it('converts between a single value and a list without losing it', () => {
		const { def, kind } = form()
		const second = def.fields[kind].options![1].value
		const list = changeLeafOp(def, { field: kind, op: 'eq', value: second }, 'in')
		expect(list.value).toEqual([second])
		expect(changeLeafOp(def, list, 'eq').value).toBe(second)
	})

	it('ignores an operator the field type does not allow', () => {
		const { def, kind } = form()
		const leaf = defaultLeaf(def, kind)
		expect(changeLeafOp(def, leaf, 'gt')).toBe(leaf)
	})
})

describe('changeLeafField', () => {
	it('resets operator and value for the new field', () => {
		const { def, ok } = form()
		expect(changeLeafField(def, ok)).toEqual({ field: ok, op: 'eq', value: true })
	})
})

describe('effectsFor', () => {
	it('does not offer require on display fields', () => {
		const { def, name, note } = form()
		expect(effectsFor(def.fields[name])).toEqual(['show', 'hide', 'require'])
		expect(effectsFor(def.fields[note])).toEqual(['show', 'hide'])
	})
})

describe('sourceFields', () => {
	it('lists input fields in layout order and skips display fields', () => {
		const { def, name, kind, url, tags, ok, age } = form()
		expect(sourceFields(def).map((source) => source.id)).toEqual([name, kind, url, tags, ok, age])
	})
})

describe('forbiddenSources and cycles', () => {
	it('forbids the target itself', () => {
		const { def, url } = form()
		expect(forbiddenSources(def, { id: 'rule_new', targets: [url] }).has(url)).toBe(true)
	})

	it('forbids fields that already depend on the target, directly or through a chain', () => {
		const { def, name, kind, url } = form()
		// url depends on kind; name depends on url.
		def.rules = [
			{ id: 'rule_aaa', effect: 'show', targets: [url], when: { all: [{ field: kind, op: 'not_empty' }] } },
			{ id: 'rule_bbb', effect: 'show', targets: [name], when: { all: [{ field: url, op: 'not_empty' }] } },
		]
		// A rule on `kind` must not read url (depends on kind) or name (depends on url via kind's dependents).
		const forbidden = forbiddenSources(def, { id: 'rule_new', targets: [kind] })
		expect(forbidden).toEqual(new Set([kind, url, name]))
	})

	it('ignores the rule being edited, so changing its own sources is not blocked by them', () => {
		const { def, name, kind } = form()
		def.rules = [{ id: 'rule_aaa', effect: 'show', targets: [name], when: { all: [{ field: kind, op: 'not_empty' }] } }]
		expect(forbiddenSources(def, def.rules[0])).toEqual(new Set([name]))
	})
})

describe('newRule', () => {
	it('creates a valid show rule reading the first allowed field', () => {
		const { def, name, kind } = form()
		const rule = newRule(def, kind)!
		expect(rule.effect).toBe('show')
		expect(rule.targets).toEqual([kind])
		expect(rule.id).toMatch(/^[a-z][a-z0-9_]{2,31}$/)
		// Never reads the target itself.
		expect(JSON.stringify(rule.when)).not.toContain(kind)
		expect(JSON.stringify(rule.when)).toContain(name)
	})

	it('returns null when there is nothing to read', () => {
		let def = emptyDefinition('T')
		const only = addField(def, 'text', { kind: 'row', index: 0 })
		def = only.definition
		expect(newRule(def, only.fieldId)).toBeNull()
		expect(newRule(emptyDefinition('T'), 'f_missing')).toBeNull()
	})

	it('gives each rule its own id', () => {
		const { def, name } = form()
		const first = newRule(def, name)!
		def.rules = [first]
		expect(newRule(def, name)!.id).not.toBe(first.id)
	})
})

describe('lookups', () => {
	it('finds rules by target with their index, and dependents by source', () => {
		const { def, name, kind, url } = form()
		def.rules = [
			{ id: 'rule_aaa', effect: 'show', targets: [url], when: { all: [{ field: kind, op: 'not_empty' }] } },
			{ id: 'rule_bbb', effect: 'hide', targets: [name, url], when: { any: [{ field: kind, op: 'eq', value: 'x' }] } },
		]
		expect(rulesTargeting(def, url).map((entry) => entry.index)).toEqual([0, 1])
		expect(rulesTargeting(def, name).map((entry) => entry.index)).toEqual([1])
		expect(dependentsOf(def, kind)).toEqual([url, name])
		expect(dependentsOf(def, url)).toEqual([])
	})

	it('reads the rule index from an error path', () => {
		expect(errorRuleIndex('rules[3].when.all[0].op')).toBe(3)
		expect(errorRuleIndex('rules')).toBeNull()
		expect(errorRuleIndex('fields.f_abc.label')).toBeNull()
	})
})

describe('edits stay consistent', () => {
	it('removing the only option a rule used does not crash helpers', () => {
		const { def, kind } = form()
		const next = updateField(def, kind, { options: [] })
		expect(defaultLeaf(next, kind)).toMatchObject({ field: kind, op: 'eq', value: '' })
	})
})
