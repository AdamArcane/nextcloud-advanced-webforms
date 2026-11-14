// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DraftDefinition, FormAction } from '../../types.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { addAction, updateAction } from '../../builder/actions.ts'
import { addField, emptyDefinition } from '../../builder/definition.ts'
import ActionsPanel from './ActionsPanel.vue'

const secretMock = vi.fn()
// A rejecting vi.fn is reported by Vitest as an unhandled rejection even when the code under test
// handles it, so the failing case swaps in a plain function.
let failSecret = false
vi.mock('../../api/forms.ts', () => ({
	createWebhookSecret: (...args: unknown[]) => {
		if (failSecret) {
			return new Promise((_resolve, reject) => setTimeout(() => reject(new Error('network')), 0))
		}
		return secretMock(...args)
	},
}))

function base(): { def: DraftDefinition, name: string, email: string } {
	let def = emptyDefinition('T')
	const name = addField(def, 'text', { kind: 'row', index: 0 })
	def = name.definition
	const email = addField(def, 'email', { kind: 'row', index: 1 })
	return { def: email.definition, name: name.fieldId, email: email.fieldId }
}

function mountPanel(definition: DraftDefinition, errors: { code: string, path: string }[] = []) {
	return mount(ActionsPanel, { props: { definition, formId: 7, errors } })
}

function lastChange(wrapper: ReturnType<typeof mountPanel>): DraftDefinition {
	const events = wrapper.emitted('change')!
	return events[events.length - 1][0] as DraftDefinition
}

function button(wrapper: ReturnType<typeof mountPanel>, text: string) {
	return wrapper.findAll('button').find((b) => b.text() === text)!
}

describe('ActionsPanel', () => {
	beforeEach(() => {
		secretMock.mockReset()
		failSecret = false
	})

	it('invites you to add the first action', () => {
		const wrapper = mountPanel(emptyDefinition('T'))
		expect(wrapper.text()).toContain('No actions yet')
		for (const label of ['Show a message', 'Redirect', 'Send an email', 'Call a webhook']) {
			expect(button(wrapper, label)).toBeDefined()
		}
	})

	it('adds each kind of action and opens it for editing', async () => {
		for (const [label, type] of [['Show a message', 'message'], ['Redirect', 'redirect'], ['Send an email', 'email'], ['Call a webhook', 'webhook']]) {
			const wrapper = mountPanel(base().def)
			await button(wrapper, label).trigger('click')
			const next = lastChange(wrapper)
			expect(next.actions).toHaveLength(1)
			expect(next.actions[0].type).toBe(type)
			expect(next.actions[0].id).toMatch(/^[a-z][a-z0-9_]{2,31}$/)
		}
	})

	it('shows an edit form for a message and writes changes back', async () => {
		const { def } = base()
		const wrapper = mountPanel(addAction(def, 'message').definition)
		await wrapper.find('.af-action__head').trigger('click')
		const textarea = wrapper.find('textarea')
		await textarea.setValue('See you soon!')
		expect(lastChange(wrapper).actions[0]).toMatchObject({ type: 'message', text: 'See you soon!' })
	})

	it('inserts an answer placeholder into the message text', async () => {
		const { def, name } = base()
		const withMessage = addAction(def, 'message').definition
		const wrapper = mountPanel(withMessage)
		await wrapper.find('.af-action__head').trigger('click')
		wrapper.findComponent({ name: 'PlaceholderMenu' }).vm.$emit('pick', name)
		expect((lastChange(wrapper).actions[0] as { text: string }).text).toBe(`Thanks, your response has been sent. {${name}}`)
	})

	const recipientSelect = (wrapper: ReturnType<typeof mountPanel>) => wrapper.findAllComponents({ name: 'NcSelect' }).find((select) => select.props('inputLabel') === 'Send to')!

	it('addresses an email to the respondent by default when there is an email field', async () => {
		const { def, email } = base()
		const wrapper = mountPanel(addAction(def, 'email').definition)
		await wrapper.find('.af-action__head').trigger('click')
		expect(wrapper.text()).toContain('at most 30 such emails')
		expect(recipientSelect(wrapper).props('modelValue')).toMatchObject({ id: email, kind: 'field' })
	})

	it('offers the owner, the signed-in respondent and the email fields in one box, and takes a typed address', async () => {
		const { def, email } = base()
		const wrapper = mountPanel(addAction(def, 'email').definition)
		await wrapper.find('.af-action__head').trigger('click')
		expect(recipientSelect(wrapper).props('options')).toEqual([
			{ id: '@owner', label: expect.any(String), kind: 'owner' },
			{ id: '@respondent', label: expect.any(String), kind: 'respondent' },
			{ id: email, label: expect.any(String), kind: 'field' },
		])

		recipientSelect(wrapper).vm.$emit('update:modelValue', { id: 'owner@example.com', label: 'owner@example.com', kind: 'address' })
		expect(lastChange(wrapper).actions[0]).toMatchObject({ to: 'owner@example.com' })
		await wrapper.setProps({ definition: lastChange(wrapper) })
		expect(recipientSelect(wrapper).props('modelValue')).toMatchObject({ id: 'owner@example.com', kind: 'address' })
		expect(wrapper.text()).not.toContain('at most 30 such emails')

		recipientSelect(wrapper).vm.$emit('update:modelValue', { id: email, label: 'Email', kind: 'field' })
		expect(lastChange(wrapper).actions[0]).toMatchObject({ to: { field: email } })

		recipientSelect(wrapper).vm.$emit('update:modelValue', null)
		expect(lastChange(wrapper).actions[0]).toMatchObject({ to: '' })
	})

	it('can address the form owner or the signed-in respondent, and says what each means', async () => {
		const wrapper = mountPanel(addAction(base().def, 'email').definition)
		await wrapper.find('.af-action__head').trigger('click')

		recipientSelect(wrapper).vm.$emit('update:modelValue', { id: '@owner', label: 'Me', kind: 'owner' })
		expect(lastChange(wrapper).actions[0]).toMatchObject({ to: { owner: true } })
		await wrapper.setProps({ definition: lastChange(wrapper) })
		expect(recipientSelect(wrapper).props('modelValue')).toMatchObject({ kind: 'owner' })
		expect(wrapper.text()).toContain('email address on your Nextcloud account')

		recipientSelect(wrapper).vm.$emit('update:modelValue', { id: '@respondent', label: 'Them', kind: 'respondent' })
		expect(lastChange(wrapper).actions[0]).toMatchObject({ to: { respondent: true } })
		await wrapper.setProps({ definition: lastChange(wrapper) })
		expect(wrapper.text()).toContain('require login')
	})

	it('still lets you type an address when the form has no email field', async () => {
		let def = emptyDefinition('T')
		def = addField(def, 'text', { kind: 'row', index: 0 }).definition
		const wrapper = mountPanel(addAction(def, 'email').definition)
		await wrapper.find('.af-action__head').trigger('click')
		expect(recipientSelect(wrapper).props('options').map((option: { kind: string }) => option.kind)).toEqual(['owner', 'respondent'])
		expect(recipientSelect(wrapper).props('taggable')).toBe(true)
	})

	it('makes a secret for a webhook, shows it once, and keeps only the reference in the form', async () => {
		secretMock.mockResolvedValue({ ref: 'whsec_abc12345', secret: 'S3CRETVALUE' })
		const wrapper = mountPanel(addAction(base().def, 'webhook').definition)
		await wrapper.find('.af-action__head').trigger('click')
		await button(wrapper, 'Generate a secret').trigger('click')
		await flushPromises()

		expect(secretMock).toHaveBeenCalledWith(7, undefined)
		const saved = lastChange(wrapper).actions[0] as { secretRef: string }
		expect(saved.secretRef).toBe('whsec_abc12345')
		expect(JSON.stringify(lastChange(wrapper))).not.toContain('S3CRETVALUE')
		expect(wrapper.text()).toContain('S3CRETVALUE')
		expect(wrapper.text()).toContain('will not be shown again')

		await button(wrapper, 'I have copied it').trigger('click')
		expect(wrapper.text()).not.toContain('S3CRETVALUE')
	})

	it('replaces the secret under the same reference when regenerating', async () => {
		secretMock.mockResolvedValue({ ref: 'whsec_abc12345', secret: 'NEWVALUE' })
		let def = addAction(base().def, 'webhook').definition
		def = updateAction(def, 0, { ...def.actions[0], secretRef: 'whsec_abc12345' } as FormAction)
		const wrapper = mountPanel(def)
		await wrapper.find('.af-action__head').trigger('click')
		expect(wrapper.text()).toContain('A secret is set')
		await button(wrapper, 'Generate a new secret').trigger('click')
		await flushPromises()
		expect(secretMock).toHaveBeenCalledWith(7, 'whsec_abc12345')
	})

	it('reports a failed secret request without changing the form', async () => {
		failSecret = true
		const wrapper = mountPanel(addAction(base().def, 'webhook').definition)
		await wrapper.find('.af-action__head').trigger('click')
		await button(wrapper, 'Generate a secret').trigger('click')
		await flushPromises()
		expect(wrapper.text()).toContain('Could not create a secret')
		expect(wrapper.emitted('change')).toBeUndefined()
	})

	it('adds and removes an "only when" condition', async () => {
		const { def, name } = base()
		const wrapper = mountPanel(addAction(def, 'webhook').definition)
		await wrapper.find('.af-action__head').trigger('click')
		await wrapper.find('input[type="checkbox"]').setValue(true)
		const when = lastChange(wrapper).actions[0].when
		expect(JSON.stringify(when)).toContain(name)

		await wrapper.setProps({ definition: lastChange(wrapper) })
		expect(wrapper.findComponent({ name: 'ConditionGroup' }).exists()).toBe(true)
		await wrapper.find('input[type="checkbox"]').setValue(false)
		expect('when' in lastChange(wrapper).actions[0]).toBe(false)
	})

	it('warns about a message that an earlier one pre-empts', async () => {
		let def = base().def
		def = addAction(addAction(def, 'message').definition, 'redirect').definition
		const wrapper = mountPanel(def)
		expect(wrapper.text()).toContain('Never used')
		expect(wrapper.findAll('.af-action__chip--warn')).toHaveLength(1)
	})

	it('reorders and deletes actions', async () => {
		let def = base().def
		def = addAction(addAction(def, 'message').definition, 'webhook').definition
		const [first, second] = def.actions.map((a) => a.id)
		const wrapper = mountPanel(def)
		const cards = wrapper.findAllComponents({ name: 'ActionCard' })
		cards[1].vm.$emit('move', -1)
		expect(lastChange(wrapper).actions.map((a) => a.id)).toEqual([second, first])
		cards[0].vm.$emit('remove')
		expect(lastChange(wrapper).actions.map((a) => a.id)).toEqual([second])
	})

	it('stops offering new actions at the limit', () => {
		let def = base().def
		for (let i = 0; i < 10; i++) {
			def = addAction(def, 'webhook').definition
		}
		const wrapper = mountPanel(def)
		expect(button(wrapper, 'Show a message').attributes('disabled')).toBeDefined()
		expect(wrapper.text()).toContain('up to 10 actions')
	})

	it('shows server problems on the action they belong to', async () => {
		let def = base().def
		def = addAction(addAction(def, 'message').definition, 'webhook').definition
		const wrapper = mountPanel(def, [{ code: 'action_bad_url', path: 'actions[1].url' }])
		const cards = wrapper.findAllComponents({ name: 'ActionCard' })
		await cards[1].find('.af-action__head').trigger('click')
		expect(cards[1].text()).toContain('https://')
		await cards[0].find('.af-action__head').trigger('click')
		expect(cards[0].text()).not.toContain('Enter a full address')
	})

	it('flags placeholders that point at a deleted field', async () => {
		let def = base().def
		def = addAction(def, 'message').definition
		def = updateAction(def, 0, { ...def.actions[0], text: 'Hi {f_gone}' } as FormAction)
		const wrapper = mountPanel(def)
		await wrapper.find('.af-action__head').trigger('click')
		expect(wrapper.text()).toContain('{f_gone}')
	})
})

describe('ActionsPanel summaries', () => {
	it('shows answers by their field label in the collapsed summary', () => {
		const { def, name } = base()
		def.fields[name].label = 'Full name'
		let withMessage = addAction(def, 'message').definition
		withMessage = updateAction(withMessage, 0, { ...withMessage.actions[0], text: `Thanks {${name}}!` } as FormAction)
		const wrapper = mountPanel(withMessage)
		expect(wrapper.find('.af-action__summary').text()).toBe('Thanks {Full name}!')
	})
})

describe('warnings on a new action', () => {
	function webhookPanel(showErrors = false) {
		const added = addAction(emptyDefinition('T'), 'webhook')
		const errors = [{ code: 'action_bad_url', path: 'actions[0].url' }]
		const wrapper = mount(ActionsPanel, {
			props: { definition: added.definition, formId: 7, errors, showErrors },
			attachTo: document.body,
		})
		return { wrapper, id: added.actionId! }
	}

	it('stays quiet until the user has been in the card', async () => {
		const { wrapper } = webhookPanel()
		const head = wrapper.get('.af-action__head')
		await head.trigger('click')
		// Clicking around the card is not editing it.
		await head.trigger('focusout')
		expect(wrapper.find('.af-action--error').exists()).toBe(false)

		// Leaving a text box is.
		await wrapper.get('.af-action__body input').trigger('focusout')
		expect(wrapper.find('.af-action--error').exists()).toBe(true)
		wrapper.unmount()
	})

	it('shows problems straight away after a failed publish', () => {
		const { wrapper } = webhookPanel(true)
		expect(wrapper.find('.af-action--error').exists()).toBe(true)
		wrapper.unmount()
	})
})
