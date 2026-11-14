/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DraftDefinition } from '../types.ts'

import { describe, expect, it } from 'vitest'
import {
	addField,
	allowedSpans,
	canMove,
	emptyDefinition,
	fieldOrder,
	largestSpanWithin,
	locateField,
	moveField,
	removeField,
	setSpan,
	updateField,
} from './definition.ts'

const ID = /^[a-z][a-z0-9_]{2,31}$/

function build(): { def: DraftDefinition, a: string, b: string, c: string } {
	let def = emptyDefinition('T')
	const a = addField(def, 'text', { kind: 'row', index: 0 })
	def = setSpan(a.definition, a.fieldId, 6)
	const b = addField(def, 'email', { kind: 'join', rowId: def.pages[0].rows[0].id })
	def = b.definition
	const c = addField(def, 'select', { kind: 'row', index: 1 })
	return { def: c.definition, a: a.fieldId, b: b.fieldId, c: c.fieldId }
}

describe('addField', () => {
	it('creates a valid id, cell and full-width row', () => {
		const { definition, fieldId } = addField(emptyDefinition('T'), 'text', { kind: 'row', index: 0 })
		expect(fieldId).toMatch(ID)
		expect(definition.fields[fieldId].type).toBe('text')
		const row = definition.pages[0].rows[0]
		expect(row.id).toMatch(ID)
		expect(row.cells[0]).toMatchObject({ span: 12, field: fieldId })
		expect(row.cells[0].id).toMatch(ID)
	})

	it('does not mutate its input', () => {
		const before = emptyDefinition('T')
		addField(before, 'text', { kind: 'row', index: 0 })
		expect(before.fields).toEqual({})
		expect(before.pages[0].rows).toEqual([])
	})

	it('gives choice fields two valid options', () => {
		const { definition, fieldId } = addField(emptyDefinition('T'), 'select', { kind: 'row', index: 0 })
		const options = definition.fields[fieldId].options!
		expect(options).toHaveLength(2)
		expect(new Set(options.map((o) => o.value)).size).toBe(2)
		for (const option of options) {
			expect(option.value).toMatch(ID)
		}
	})

	it('joins a row with the largest span that fits, then falls back to a new row', () => {
		const { def } = build()
		const first = def.pages[0].rows[0]
		expect(first.cells.map((cell) => cell.span)).toEqual([6, 6])
		// A full row has no room, so joining goes to a new row directly below it.
		const rowCount = def.pages[0].rows.length
		const joined = addField(def, 'text', { kind: 'join', rowId: first.id }).definition
		expect(joined.pages[0].rows).toHaveLength(rowCount + 1)
		expect(joined.pages[0].rows[1].cells).toHaveLength(1)
	})

	it('shares a row when there is room', () => {
		let def = emptyDefinition('T')
		def = addField(def, 'text', { kind: 'row', index: 0 }).definition
		def = setSpan(def, Object.keys(def.fields)[0], 6)
		const joined = addField(def, 'email', { kind: 'join', rowId: def.pages[0].rows[0].id }).definition
		expect(joined.pages[0].rows).toHaveLength(1)
		expect(joined.pages[0].rows[0].cells.map((cell) => cell.span)).toEqual([6, 6])
	})

	it('generates unique ids', () => {
		let def = emptyDefinition('T')
		for (let i = 0; i < 50; i++) {
			def = addField(def, 'text', { kind: 'row', index: i }).definition
		}
		expect(Object.keys(def.fields)).toHaveLength(50)
	})
})

describe('spans', () => {
	it('picks the largest allowed span within the space', () => {
		expect(largestSpanWithin(12)).toBe(12)
		expect(largestSpanWithin(7)).toBe(6)
		expect(largestSpanWithin(5)).toBe(4)
		expect(largestSpanWithin(2)).toBeNull()
	})

	it('only offers spans that keep the row within 12', () => {
		let def = emptyDefinition('T')
		const a = addField(def, 'text', { kind: 'row', index: 0 })
		def = setSpan(a.definition, a.fieldId, 8)
		const b = addField(def, 'email', { kind: 'join', rowId: def.pages[0].rows[0].id })
		expect(locateField(b.definition, b.fieldId)!.cell.span).toBe(4)
		expect(allowedSpans(b.definition, b.fieldId)).toEqual([3, 4])
		expect(allowedSpans(b.definition, a.fieldId)).toEqual([3, 4, 6, 8])
	})

	it('refuses a span that would overflow', () => {
		const { def, b } = build()
		expect(setSpan(def, b, 12)).toBe(def)
	})
})

describe('moveField', () => {
	it('moves a whole row up and down', () => {
		const { def, a, c } = build()
		expect(canMove(def, a, 'rowUp')).toBe(false)
		const moved = moveField(def, c, 'rowUp')
		expect(fieldOrder(moved)[0]).toBe(c)
		expect(fieldOrder(moved)).toHaveLength(3)
	})

	it('reorders within a row', () => {
		let def = emptyDefinition('T')
		const a = addField(def, 'text', { kind: 'row', index: 0 })
		def = setSpan(a.definition, a.fieldId, 6)
		const b = addField(def, 'email', { kind: 'join', rowId: def.pages[0].rows[0].id })
		const moved = moveField(b.definition, b.fieldId, 'left')
		expect(fieldOrder(moved)).toEqual([b.fieldId, a.fieldId])
		expect(canMove(moved, b.fieldId, 'left')).toBe(false)
	})

	it('splits a field into its own full-width row', () => {
		let def = emptyDefinition('T')
		const a = addField(def, 'text', { kind: 'row', index: 0 })
		def = setSpan(a.definition, a.fieldId, 6)
		const b = addField(def, 'email', { kind: 'join', rowId: def.pages[0].rows[0].id })
		const split = moveField(b.definition, a.fieldId, 'ownRow')
		expect(split.pages[0].rows).toHaveLength(2)
		expect(locateField(split, a.fieldId)!.cell.span).toBe(12)
		expect(locateField(split, a.fieldId)!.rowIndex).toBe(1)
	})

	it('joins the row above when there is room, shrinking the cell, and drops the emptied row', () => {
		let def = emptyDefinition('T')
		const a = addField(def, 'text', { kind: 'row', index: 0 })
		def = setSpan(a.definition, a.fieldId, 6)
		const b = addField(def, 'email', { kind: 'row', index: 1 })
		expect(canMove(b.definition, b.fieldId, 'joinAbove')).toBe(true)
		const joined = moveField(b.definition, b.fieldId, 'joinAbove')
		expect(joined.pages[0].rows).toHaveLength(1)
		expect(joined.pages[0].rows[0].cells.map((cell) => cell.span)).toEqual([6, 6])
	})

	it('cannot join a full row', () => {
		const { def, c } = build()
		// Row 0 is 6 + 6, so there is no room for the select below it.
		expect(canMove(def, c, 'joinAbove')).toBe(false)
		expect(moveField(def, c, 'joinAbove')).toBe(def)
	})

	it('keeps every field in exactly one cell through a series of moves', () => {
		let { def, a, b, c } = build()
		def = moveField(def, c, 'joinAbove')
		def = moveField(def, a, 'ownRow')
		def = moveField(def, b, 'rowUp')
		expect(fieldOrder(def).sort()).toEqual([a, b, c].sort())
		for (const row of def.pages[0].rows) {
			expect(row.cells.reduce((sum, cell) => sum + cell.span, 0)).toBeLessThanOrEqual(12)
			expect(row.cells.length).toBeGreaterThan(0)
		}
	})
})

describe('updateField', () => {
	it('sets and removes properties without touching the original', () => {
		const { def, a } = build()
		const next = updateField(def, a, { label: 'Name', placeholder: 'x' })
		expect(next.fields[a]).toMatchObject({ label: 'Name', placeholder: 'x' })
		expect(def.fields[a].label).not.toBe('Name')
		expect('placeholder' in updateField(next, a, { placeholder: undefined }).fields[a]).toBe(false)
	})

	it('ignores unknown fields', () => {
		const { def } = build()
		expect(updateField(def, 'f_missing', { label: 'x' })).toBe(def)
	})
})

describe('removeField', () => {
	it('removes the field, its cell and an emptied row', () => {
		const { def, c } = build()
		const next = removeField(def, c)
		expect(c in next.fields).toBe(false)
		expect(next.pages[0].rows).toHaveLength(1)
		expect(fieldOrder(next)).not.toContain(c)
	})

	it('cleans rules: drops removed targets and conditions, and rules left empty', () => {
		let { def, a, b, c } = build()
		def.rules = [
			{ id: 'rule_one', effect: 'show', targets: [a, b], when: { all: [{ field: c, op: 'eq', value: 'x' }] } },
			{ id: 'rule_two', effect: 'hide', targets: [a], when: { any: [{ field: b, op: 'not_empty' }, { field: c, op: 'empty' }] } },
			{ id: 'rule_three', effect: 'require', targets: [c], when: { all: [{ field: a, op: 'not_empty' }] } },
		]
		// Removing the only condition field kills rule_one; rule_two keeps its other condition.
		let next = removeField(def, c)
		expect(next.rules.map((rule) => rule.id)).toEqual(['rule_two'])
		expect(next.rules[0].when).toEqual({ any: [{ field: b, op: 'not_empty' }] })
		// Removing a target keeps the rule while it still has one.
		next = removeField(def, a)
		const one = next.rules.find((rule) => rule.id === 'rule_one')!
		expect(one.targets).toEqual([b])
		expect(next.rules.find((rule) => rule.id === 'rule_three')).toBeUndefined()
	})
})

describe('reactive input', () => {
	it('edits a Vue reactive definition (structuredClone would throw on the proxy)', async () => {
		const { reactive } = await import('vue')
		const def = reactive(emptyDefinition('T'))
		const { definition, fieldId } = addField(def, 'text', { kind: 'row', index: 0 })
		expect(Object.keys(definition.fields)).toEqual([fieldId])
		expect(() => removeField(reactive(definition), fieldId)).not.toThrow()
	})
})
