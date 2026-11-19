// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OwnerForm } from '../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { FORM_STATUS_PUBLISHED, FORM_STATUS_UNPUBLISHED } from '../types.ts'
import OverviewView from './OverviewView.vue'

function form(id: number, patch: Partial<OwnerForm> = {}): OwnerForm {
	return {
		id,
		title: 'Form ' + id,
		description: null,
		status: FORM_STATUS_PUBLISHED,
		publicToken: 'tok' + id,
		latestVersionId: id,
		createdAt: 1000,
		updatedAt: 1000 + id,
		hasUnpublishedChanges: false,
		newCount: 0,
		...patch,
	}
}

function mountOverview(forms: OwnerForm[], props: { canCreate?: boolean, creating?: boolean } = {}) {
	return mount(OverviewView, { props: { forms, canCreate: true, creating: false, ...props } })
}

describe('the overview screen', () => {
	it('shows the totals', () => {
		const wrapper = mountOverview([form(1, { newCount: 3 }), form(2, { status: FORM_STATUS_UNPUBLISHED, latestVersionId: null })])
		const tiles = wrapper.findAll('.af-tile').map((tile) => [tile.find('.af-tile__value').text(), tile.find('.af-tile__label').text()])
		expect(tiles).toEqual([['2', 'Forms'], ['1', 'Published'], ['1', 'Drafts'], ['3', 'New responses']])
	})

	it('says so when nothing needs attention', () => {
		const wrapper = mountOverview([form(1)])
		expect(wrapper.text()).toContain('Nothing needs you right now.')
	})

	it('links a form with new responses to its responses, and one with unpublished changes to the editor', () => {
		const wrapper = mountOverview([form(1, { newCount: 2 }), form(2, { hasUnpublishedChanges: true })])
		const links = wrapper.findAll('#af-overview-attention ~ ul a').map((link) => link.attributes('href'))
		expect(links).toEqual(['#/f/1/responses', '#/f/2'])
	})

	it('lists recently edited forms, newest first, as links to the editor', () => {
		const wrapper = mountOverview([form(1), form(2), form(3)])
		const links = wrapper.findAll('[aria-labelledby="af-overview-recent"] a').map((link) => link.attributes('href'))
		expect(links).toEqual(['#/f/3', '#/f/2', '#/f/1'])
	})

	it('asks to create a form, unless the user may not', async () => {
		const wrapper = mountOverview([form(1)])
		await wrapper.find('button.af-overview__new').trigger('click')
		expect(wrapper.emitted('create')).toHaveLength(1)

		const blocked = mountOverview([form(1)], { canCreate: false })
		expect(blocked.find('button.af-overview__new').attributes('disabled')).toBeDefined()
	})
})
