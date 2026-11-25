/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OwnerForm } from './types.ts'

import { describe, expect, it } from 'vitest'
import { formSections, sortFolders } from './folders.ts'

function form(id: number, over: Partial<OwnerForm> = {}): OwnerForm {
	return { id, title: 'Form ' + id, description: null, status: 0, publicToken: null, latestVersionId: null, createdAt: 0, updatedAt: 0, ...over }
}

const outline = (sections: ReturnType<typeof formSections>) => sections.map((section) => [section.key, section.forms.map((one) => one.id)])

describe('sidebar sections', () => {
	it('is only My forms when there are no folders and nothing is shared', () => {
		expect(outline(formSections([form(1)], []))).toEqual([['mine', [1]]])
		expect(outline(formSections([], []))).toEqual([['mine', []]])
	})

	it('lists folders first by name, empty ones too, and takes their forms out of the other sections', () => {
		const forms = [form(1, { folderId: 7 }), form(2), form(3, { role: 'editor', folderId: 7 }), form(4, { role: 'reviewer' })]
		const sections = formSections(forms, [{ id: 7, name: 'Work' }, { id: 8, name: 'events' }])
		expect(outline(sections)).toEqual([['folder:8', []], ['folder:7', [1, 3]], ['mine', [2]], ['shared', [4]]])
	})

	it('keeps a form whose folder is unknown, as one in no folder', () => {
		const forms = [form(1, { folderId: 99 }), form(2, { folderId: 99, role: 'editor' })]
		expect(outline(formSections(forms, []))).toEqual([['mine', [1]], ['shared', [2]]])
	})

	it('sorts names with numbers by their value and ignores capitals', () => {
		const names = sortFolders([{ id: 1, name: 'Year 10' }, { id: 2, name: 'year 9' }, { id: 3, name: 'Archive' }]).map((folder) => folder.name)
		expect(names).toEqual(['Archive', 'year 9', 'Year 10'])
	})
})
