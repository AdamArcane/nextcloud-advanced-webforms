/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DraftDefinition, FormAction } from '../types.ts'

import { describe, expect, it } from 'vitest'
import { addField, emptyDefinition, fieldOrder } from './definition.ts'
import { FIELD_ID_PATTERN, fieldIdProblem, followLabel, idFromLabel, renameField, slugFromLabel } from './fieldId.ts'

function form(): { def: DraftDefinition, a: string, b: string, c: string } {
	let def = emptyDefinition('T')
	const a = addField(def, 'text', { kind: 'row', index: 0 })
	def = a.definition
	const b = addField(def, 'email', { kind: 'row', index: 1 })
	def = b.definition
	const c = addField(def, 'select', { kind: 'row', index: 2 })
	return { def: c.definition, a: a.fieldId, b: b.fieldId, c: c.fieldId }
}

describe('fieldIdProblem', () => {
	it('accepts a free id that matches the pattern', () => {
		const { def, a } = form()
		expect(fieldIdProblem(def, a, 'customer_email', [])).toBeNull()
		expect(fieldIdProblem(def, a, 'abc', [])).toBeNull()
		expect(fieldIdProblem(def, a, 'a' + 'b'.repeat(31), [])).toBeNull()
	})

	it('rejects ids the server would reject', () => {
		const { def, a } = form()
		for (const bad of ['', 'ab', 'Email', '1email', '_email', 'my-field', 'my field', 'émail', 'a'.repeat(33)]) {
			expect(fieldIdProblem(def, a, bad, []), bad).toBe('pattern')
		}
	})

	it('reports the unchanged id, an id another field has, and a retired id', () => {
		const { def, a, b } = form()
		expect(fieldIdProblem(def, a, a, [])).toBe('same')
		expect(fieldIdProblem(def, a, b, [])).toBe('taken')
		expect(fieldIdProblem(def, a, 'old_field', ['old_field'])).toBe('retired')
	})

	it('uses the same pattern as the generated ids', () => {
		const { a } = form()
		expect(a).toMatch(FIELD_ID_PATTERN)
	})
})

describe('renameField', () => {
	it('renames the field, keeps its position and settings, and moves its layout cell', () => {
		const { def, a, b, c } = form()
		const next = renameField(def, b, 'customer_email')
		expect(Object.keys(next.fields)).toEqual([a, 'customer_email', c])
		expect(next.fields.customer_email).toEqual(def.fields[b])
		expect(b in next.fields).toBe(false)
		expect(fieldOrder(next)).toEqual([a, 'customer_email', c])
	})

	it('does not mutate its input', () => {
		const { def, b } = form()
		const before = JSON.stringify(def)
		renameField(def, b, 'customer_email')
		expect(JSON.stringify(def)).toBe(before)
	})

	it('rewrites rule targets and conditions, at any depth', () => {
		const { def, a, b, c } = form()
		def.rules = [
			{ id: 'rule_one', effect: 'show', targets: [b, c], when: { all: [{ field: a, op: 'not_empty' }] } },
			{ id: 'rule_two', effect: 'hide', targets: [a], when: { any: [{ field: b, op: 'not_empty' }, { all: [{ field: b, op: 'empty' }, { field: c, op: 'eq', value: 'x' }] }] } },
		]
		const next = renameField(def, b, 'customer_email')
		expect(next.rules[0].targets).toEqual(['customer_email', c])
		expect(next.rules[1].when).toEqual({ any: [
			{ field: 'customer_email', op: 'not_empty' },
			{ all: [{ field: 'customer_email', op: 'empty' }, { field: c, op: 'eq', value: 'x' }] },
		] })
		// Conditions on other fields are untouched.
		expect(next.rules[0].when).toEqual({ all: [{ field: a, op: 'not_empty' }] })
		expect(JSON.stringify(next.rules)).not.toContain(b)
	})

	it('rewrites action conditions, the email recipient field, and placeholders in text', () => {
		const { def, a, b } = form()
		def.actions = [
			{ id: 'act_msg', type: 'message', text: `Thanks {${a}}, we will write to {${b}}.`, when: { all: [{ field: b, op: 'not_empty' }] } },
			{ id: 'act_mail', type: 'email', to: { field: b }, subject: `Hi {${b}}`, body: `Name {${a}} Email {${b}} again {${b}}` },
			{ id: 'act_go', type: 'redirect', url: `https://example.com/ok?e={${b}}&n={${a}}` },
			{ id: 'act_hook', type: 'webhook', url: 'https://example.com/hook', secretRef: 'whsec_abcdef12' },
		] as FormAction[]
		const next = renameField(def, b, 'customer_email')
		expect(next.actions[0]).toMatchObject({
			text: `Thanks {${a}}, we will write to {customer_email}.`,
			when: { all: [{ field: 'customer_email', op: 'not_empty' }] },
		})
		expect(next.actions[1]).toMatchObject({
			to: { field: 'customer_email' },
			subject: 'Hi {customer_email}',
			body: `Name {${a}} Email {customer_email} again {customer_email}`,
		})
		expect(next.actions[2]).toMatchObject({ url: `https://example.com/ok?e={customer_email}&n={${a}}` })
		expect(next.actions[3]).toEqual(def.actions[3])
		expect(JSON.stringify(next.actions)).not.toContain(b)
	})

	it('keeps a Tables mapping pointing at a renamed field', () => {
		const { def, a, b } = form()
		def.actions = [{ id: 'act_tbl', type: 'tables', tableId: 3, map: { 11: a, 12: b, 13: '@submitted' } }] as FormAction[]
		expect(renameField(def, b, 'customer_email').actions[0]).toEqual({
			id: 'act_tbl', type: 'tables', tableId: 3, map: { 11: a, 12: 'customer_email', 13: '@submitted' },
		})
	})

	it('leaves a fixed email address alone', () => {
		const { def, b } = form()
		def.actions = [{ id: 'act_mail', type: 'email', to: 'owner@example.com', subject: 's', body: 'b' }] as FormAction[]
		expect(renameField(def, b, 'customer_email').actions[0]).toEqual(def.actions[0])
	})

	it('does not confuse an id that is part of a longer one', () => {
		const { def, a } = form()
		def.fields.abcd = { type: 'text', label: 'Longer' }
		def.fields.abc = { type: 'text', label: 'Shorter' }
		def.actions = [{ id: 'act_msg', type: 'message', text: '{abc} and {abcd}' }] as FormAction[]
		const next = renameField(def, 'abc', 'renamed')
		expect((next.actions[0] as { text: string }).text).toBe('{renamed} and {abcd}')
		expect(a).toBeDefined()
	})

	it('refuses an invalid, taken or retired id and returns the same definition', () => {
		const { def, a, b } = form()
		expect(renameField(def, a, 'Bad-Id')).toBe(def)
		expect(renameField(def, a, b)).toBe(def)
		expect(renameField(def, a, 'gone_field', ['gone_field'])).toBe(def)
		expect(renameField(def, a, a)).toBe(def)
		expect(renameField(def, 'f_missing', 'new_field')).toBe(def)
	})

	it('can be applied to a Vue reactive definition', async () => {
		const { reactive } = await import('vue')
		const { def, b } = form()
		expect(() => renameField(reactive(def), b, 'customer_email')).not.toThrow()
	})
})

describe('ids that follow the label', () => {
	const base = (id: string, label: string, extra: Record<string, unknown> = {}) => ({
		...emptyDefinition('T'),
		fields: { [id]: { type: 'text', label, ...extra } as never },
		pages: [{ id: 'p_1', title: null, rows: [{ id: 'r_1', cells: [{ id: 'c_1', span: 12, field: id }] }] }],
	})

	it('makes a slug from a label', () => {
		expect(slugFromLabel('First Name')).toBe('f_first_name')
		expect(slugFromLabel('  Café & Crème! ')).toBe('f_cafe_creme')
		expect(slugFromLabel('???')).toBeNull()
		expect(slugFromLabel('x'.repeat(60))?.length).toBe(32)
	})

	it('adds a number when the slug is taken', () => {
		expect(idFromLabel('Email', new Set(['f_email']))).toBe('f_email_2')
		expect(idFromLabel('Email', new Set(['f_email', 'f_email_2']))).toBe('f_email_3')
	})

	it('renames a random id to follow the label', () => {
		const result = followLabel(base('f_abc123', 'First Name'), 'f_abc123', 'Text', [], new Set())
		expect(result.fieldId).toBe('f_first_name')
		expect(Object.keys(result.definition.fields)).toEqual(['f_first_name'])
	})

	it('keeps following while the id matches the previous label', () => {
		const result = followLabel(base('f_first', 'First Name'), 'f_first', 'First', [], new Set())
		expect(result.fieldId).toBe('f_first_name')
	})

	it('leaves an id the owner chose, a frozen field, a retired id and display fields alone', () => {
		expect(followLabel(base('customer_email', 'Email'), 'customer_email', 'E', [], new Set()).fieldId).toBe('customer_email')
		expect(followLabel(base('f_abc123', 'Name'), 'f_abc123', 'Text', [], new Set(['f_abc123'])).fieldId).toBe('f_abc123')
		expect(followLabel(base('f_abc123', 'Name'), 'f_abc123', 'Text', ['f_name'], new Set()).fieldId).toBe('f_name_2')
		const heading = { ...base('f_abc123', ''), fields: { f_abc123: { type: 'heading', text: 'Hi' } as never } }
		expect(followLabel(heading, 'f_abc123', undefined, [], new Set()).fieldId).toBe('f_abc123')
	})
})
