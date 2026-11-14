/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DraftDefinition } from '../types.ts'

import { describe, expect, it } from 'vitest'
import {
	ACTION_TYPES,
	addAction,
	defaultWhen,
	emailFields,
	errorActionIndex,
	MAX_ACTIONS,
	moveAction,
	removeAction,
	setWhen,
	unknownPlaceholders,
	unreachableResponseActions,
	updateAction,
	withPlaceholder,
} from './actions.ts'
import { addField, emptyDefinition } from './definition.ts'

function form(): { def: DraftDefinition, name: string, email: string } {
	let def = emptyDefinition('T')
	const name = addField(def, 'text', { kind: 'row', index: 0 })
	def = name.definition
	const email = addField(def, 'email', { kind: 'row', index: 1 })
	return { def: email.definition, name: name.fieldId, email: email.fieldId }
}

describe('addAction', () => {
	it('creates each type with a valid id and starting content', () => {
		for (const type of ACTION_TYPES) {
			const { def } = form()
			const result = addAction(def, type)
			expect(result.actionId).toMatch(/^[a-z][a-z0-9_]{2,31}$/)
			expect(result.definition.actions).toHaveLength(1)
			expect(result.definition.actions[0]).toMatchObject({ id: result.actionId, type })
		}
	})

	it('addresses a new email to the first email field when there is one', () => {
		const { def, email } = form()
		expect(addAction(def, 'email').definition.actions[0]).toMatchObject({ to: { field: email } })
		expect(addAction(emptyDefinition('T'), 'email').definition.actions[0]).toMatchObject({ to: '' })
	})

	it('does not mutate its input and gives every action its own id', () => {
		const { def } = form()
		let next = def
		for (let i = 0; i < 5; i++) {
			next = addAction(next, 'message').definition
		}
		expect(def.actions).toHaveLength(0)
		expect(new Set(next.actions.map((a) => a.id)).size).toBe(5)
	})

	it('stops at the server limit', () => {
		let { def } = form()
		for (let i = 0; i < MAX_ACTIONS; i++) {
			def = addAction(def, 'message').definition
		}
		const full = addAction(def, 'webhook')
		expect(full.actionId).toBeNull()
		expect(full.definition).toBe(def)
	})
})

describe('updating, removing and moving', () => {
	it('replaces one action', () => {
		const { def } = form()
		let next = addAction(def, 'message').definition
		next = updateAction(next, 0, { ...next.actions[0], text: 'Hello' } as never)
		expect(next.actions[0]).toMatchObject({ text: 'Hello' })
		expect(updateAction(next, 5, next.actions[0])).toBe(next)
	})

	it('removes an action', () => {
		let { def } = form()
		def = addAction(addAction(def, 'message').definition, 'webhook').definition
		const kept = def.actions[1].id
		expect(removeAction(def, 0).actions.map((a) => a.id)).toEqual([kept])
	})

	it('moves up and down, and stays put at the ends', () => {
		let { def } = form()
		def = addAction(addAction(addAction(def, 'message').definition, 'redirect').definition, 'webhook').definition
		const [a, b, c] = def.actions.map((x) => x.id)
		expect(moveAction(def, 2, -1).actions.map((x) => x.id)).toEqual([a, c, b])
		expect(moveAction(def, 0, 1).actions.map((x) => x.id)).toEqual([b, a, c])
		expect(moveAction(def, 0, -1)).toBe(def)
		expect(moveAction(def, 2, 1)).toBe(def)
	})
})

describe('conditions', () => {
	it('offers a starting condition only when there is a field to read', () => {
		const { def, name } = form()
		expect(defaultWhen(def)).toEqual({ all: [{ field: name, op: 'not_empty' }] })
		expect(defaultWhen(emptyDefinition('T'))).toBeNull()
	})

	it('sets and clears an action condition', () => {
		const { def } = form()
		const action = addAction(def, 'webhook').definition.actions[0]
		const withWhen = setWhen(action, { all: [{ field: 'f_x', op: 'not_empty' }] })
		expect(withWhen.when).toBeDefined()
		expect('when' in setWhen(withWhen, null)).toBe(false)
	})
})

describe('placeholders', () => {
	it('appends with a separating space only when needed', () => {
		expect(withPlaceholder('', 'f_name')).toBe('{f_name}')
		expect(withPlaceholder('Hi', 'f_name')).toBe('Hi {f_name}')
		expect(withPlaceholder('Hi ', 'f_name')).toBe('Hi {f_name}')
		expect(withPlaceholder('Hi\n', 'f_name')).toBe('Hi\n{f_name}')
	})

	it('finds placeholders that name a field that is gone', () => {
		const { def, name } = form()
		expect(unknownPlaceholders(`Hi {${name}} {f_gone} {f_gone}`, def)).toEqual(['f_gone'])
		expect(unknownPlaceholders('no braces', def)).toEqual([])
	})
})

describe('the shared message and redirect slot', () => {
	it('flags responses that can never be shown because an earlier one always applies', () => {
		let { def } = form()
		def = addAction(addAction(addAction(def, 'message').definition, 'redirect').definition, 'webhook').definition
		const [first, second, hook] = def.actions.map((a) => a.id)
		expect(unreachableResponseActions(def)).toEqual([second])
		expect(unreachableResponseActions(def)).not.toContain(first)
		expect(unreachableResponseActions(def)).not.toContain(hook)
	})

	it('does not flag later responses when the earlier one has a condition', () => {
		let { def } = form()
		def = addAction(addAction(def, 'message').definition, 'redirect').definition
		def = updateAction(def, 0, setWhen(def.actions[0], { all: [{ field: 'f_x', op: 'not_empty' }] }))
		expect(unreachableResponseActions(def)).toEqual([])
	})
})

describe('errors and lookups', () => {
	it('reads the action index from an error path', () => {
		expect(errorActionIndex('actions[2].url')).toBe(2)
		expect(errorActionIndex('actions')).toBeNull()
		expect(errorActionIndex('rules[1].when')).toBeNull()
	})

	it('lists email fields for recipients', () => {
		const { def, email } = form()
		expect(emailFields(def).map((f) => f.id)).toEqual([email])
	})
})
