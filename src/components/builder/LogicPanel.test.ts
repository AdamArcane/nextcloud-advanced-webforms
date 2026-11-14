// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DraftDefinition } from '../../types.ts'
import type { Rule } from '../../rules/types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { addField, emptyDefinition } from '../../builder/definition.ts'
import LogicPanel from './LogicPanel.vue'

function twoFields(): { def: DraftDefinition, a: string, b: string } {
	let def = emptyDefinition('T')
	const a = addField(def, 'select', { kind: 'row', index: 0 })
	def = a.definition
	const b = addField(def, 'text', { kind: 'row', index: 1 })
	return { def: b.definition, a: a.fieldId, b: b.fieldId }
}

function lastRules(wrapper: ReturnType<typeof mount>): Rule[] {
	const events = wrapper.emitted('rules')!
	return events[events.length - 1][0] as Rule[]
}

describe('LogicPanel', () => {
	it('says there are no rules and cannot add one when there is nothing to read', () => {
		let def = emptyDefinition('T')
		const only = addField(def, 'text', { kind: 'row', index: 0 })
		def = only.definition
		const wrapper = mount(LogicPanel, { props: { definition: def, fieldId: only.fieldId, errors: [] } })
		expect(wrapper.text()).toContain('No rules yet')
		const add = wrapper.findAll('button').find((button) => button.text() === 'Add rule')!
		expect(add.attributes('disabled')).toBeDefined()
		expect(wrapper.text()).toContain('Add another input field first')
	})

	it('adds a valid show rule on the selected field that reads another field', async () => {
		const { def, a, b } = twoFields()
		const wrapper = mount(LogicPanel, { props: { definition: def, fieldId: b, errors: [] } })
		await wrapper.findAll('button').find((button) => button.text() === 'Add rule')!.trigger('click')
		const rules = lastRules(wrapper)
		expect(rules).toHaveLength(1)
		expect(rules[0]).toMatchObject({ effect: 'show', targets: [b] })
		expect(JSON.stringify(rules[0].when)).toContain(a)
		expect(JSON.stringify(rules[0].when)).not.toContain(b)
	})

	it('renders existing rules for the field, with their conditions', () => {
		const { def, a, b } = twoFields()
		def.rules = [{ id: 'rule_aaa', effect: 'hide', targets: [b], when: { all: [{ field: a, op: 'not_empty' }] } }]
		const wrapper = mount(LogicPanel, { props: { definition: def, fieldId: b, errors: [] } })
		expect(wrapper.findAllComponents({ name: 'RuleCard' })).toHaveLength(1)
		expect(wrapper.text()).toContain('This field is hidden when')
	})

	it('switches a rule between show, hide and require', async () => {
		const { def, a, b } = twoFields()
		def.rules = [{ id: 'rule_aaa', effect: 'show', targets: [b], when: { all: [{ field: a, op: 'not_empty' }] } }]
		const wrapper = mount(LogicPanel, { props: { definition: def, fieldId: b, errors: [] } })
		const require = wrapper.findAll('[role="radio"]').find((button) => button.text() === 'Require')!
		await require.trigger('click')
		expect(lastRules(wrapper)[0].effect).toBe('require')
	})

	it('does not offer Require on display fields', () => {
		let { def, a } = twoFields()
		const note = addField(def, 'paragraph', { kind: 'row', index: 2 })
		def = note.definition
		def.rules = [{ id: 'rule_aaa', effect: 'show', targets: [note.fieldId], when: { all: [{ field: a, op: 'not_empty' }] } }]
		const wrapper = mount(LogicPanel, { props: { definition: def, fieldId: note.fieldId, errors: [] } })
		const labels = wrapper.findAll('[role="radio"]').map((button) => button.text())
		expect(labels).toContain('Show')
		expect(labels).toContain('Hide')
		expect(labels).not.toContain('Require')
	})

	it('adds a second condition, then switches the group to Any', async () => {
		const { def, a, b } = twoFields()
		def.rules = [{ id: 'rule_aaa', effect: 'show', targets: [b], when: { all: [{ field: a, op: 'not_empty' }] } }]
		const wrapper = mount(LogicPanel, { props: { definition: def, fieldId: b, errors: [] } })
		await wrapper.findAll('button').find((button) => button.text() === 'Add condition')!.trigger('click')
		let rule = lastRules(wrapper)[0]
		expect('all' in rule.when && rule.when.all).toHaveLength(2)

		await wrapper.setProps({ definition: { ...def, rules: [rule] } })
		await wrapper.findAll('[role="radio"]').find((button) => button.text() === 'Any')!.trigger('click')
		rule = lastRules(wrapper)[0]
		expect('any' in rule.when && rule.when.any).toHaveLength(2)
	})

	it('adds a nested group of the opposite kind', async () => {
		const { def, a, b } = twoFields()
		def.rules = [{ id: 'rule_aaa', effect: 'show', targets: [b], when: { all: [{ field: a, op: 'not_empty' }] } }]
		const wrapper = mount(LogicPanel, { props: { definition: def, fieldId: b, errors: [] } })
		await wrapper.findAll('button').find((button) => button.text() === 'Add group')!.trigger('click')
		const when = lastRules(wrapper)[0].when
		expect('all' in when).toBe(true)
		const members = 'all' in when ? when.all : []
		expect(members).toHaveLength(2)
		expect('any' in members[1]).toBe(true)
	})

	it('will not remove the last condition of a rule', () => {
		const { def, a, b } = twoFields()
		def.rules = [{ id: 'rule_aaa', effect: 'show', targets: [b], when: { all: [{ field: a, op: 'not_empty' }] } }]
		const wrapper = mount(LogicPanel, { props: { definition: def, fieldId: b, errors: [] } })
		const remove = wrapper.find('button[aria-label="Remove condition"]')
		expect(remove.attributes('disabled')).toBeDefined()
	})

	it('removes a nested group when its last condition is removed', async () => {
		const { def, a, b } = twoFields()
		def.rules = [{
			id: 'rule_aaa',
			effect: 'show',
			targets: [b],
			when: { all: [{ field: a, op: 'not_empty' }, { any: [{ field: a, op: 'empty' }] }] },
		}]
		const wrapper = mount(LogicPanel, { props: { definition: def, fieldId: b, errors: [] } })
		const removes = wrapper.findAll('button[aria-label="Remove condition"]')
		await removes[removes.length - 1].trigger('click')
		const when = lastRules(wrapper)[0].when
		expect('all' in when && when.all).toHaveLength(1)
	})

	it('stops applying a shared rule to one field without deleting it for the others', async () => {
		const { def, a, b } = twoFields()
		def.rules = [{ id: 'rule_aaa', effect: 'show', targets: [b, 'f_other'], when: { all: [{ field: a, op: 'not_empty' }] } }]
		def.fields.f_other = { type: 'text', label: 'Other' }
		const wrapper = mount(LogicPanel, { props: { definition: def, fieldId: b, errors: [] } })
		expect(wrapper.text()).toContain('Also applies to: Other')
		await wrapper.findAll('button').find((button) => button.text().includes('Stop applying'))!.trigger('click')
		expect(lastRules(wrapper)[0].targets).toEqual(['f_other'])
	})

	it('shows validation messages on the rule they belong to', () => {
		const { def, a, b } = twoFields()
		def.rules = [
			{ id: 'rule_aaa', effect: 'show', targets: [b], when: { all: [{ field: a, op: 'not_empty' }] } },
			{ id: 'rule_bbb', effect: 'hide', targets: [b], when: { all: [{ field: a, op: 'empty' }] } },
		]
		const wrapper = mount(LogicPanel, {
			props: { definition: def, fieldId: b, errors: [{ code: 'cond_bad_value', path: 'rules[1].when.all[0].value' }] },
		})
		const cards = wrapper.findAllComponents({ name: 'RuleCard' })
		expect(cards[0].text()).not.toContain('valid value')
		expect(cards[1].text()).toContain('needs a valid value')
	})

	it('lets you jump to the fields this field controls', async () => {
		const { def, a, b } = twoFields()
		def.rules = [{ id: 'rule_aaa', effect: 'show', targets: [b], when: { all: [{ field: a, op: 'not_empty' }] } }]
		const wrapper = mount(LogicPanel, { props: { definition: def, fieldId: a, errors: [] } })
		await flushPromises()
		const jump = wrapper.findAll('button').find((button) => button.text() === 'Short text')!
		await jump.trigger('click')
		expect(wrapper.emitted('select')![0]).toEqual([b])
	})
})
