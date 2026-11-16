/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// What the review step lists before a form is sent (docs/SCHEMA.md section 5.2).

import type { LayoutPage } from './layout/visibleLayout.ts'
import type { FormField } from './types.ts'

import { displayAnswer } from './entries/format.ts'
import { isBooleanType, isInputType } from './rules/fieldTypes.ts'

export interface ReviewItem {
	id: string
	label: string
	/** The answer as people read it. Empty when `missing`. */
	text: string
	/** A required field with no answer yet. */
	missing: boolean
}

export interface ReviewGroup {
	/** Index among the pages a respondent can reach, so it can be jumped to. */
	pageIndex: number
	title: string | null
	items: ReviewItem[]
}

export interface Review {
	groups: ReviewGroup[]
	/** Optional questions with no answer. They are counted, not listed, so the review stays short. */
	skipped: number
}

/** Whether a field has no answer yet, the way required fields are checked. */
export function isUnanswered(field: FormField, value: unknown): boolean {
	if (isBooleanType(field.type)) {
		return value !== true
	}
	if (Array.isArray(value)) {
		return value.length === 0
	}
	return typeof value !== 'string' || value.trim() === ''
}

/**
 * The answers to list, page by page, for the pages that can be reached right now. `pages` holds only
 * the shown cells (hidden fields and fields a rule hides are not in it), so the list is what will be
 * sent. Display blocks and hidden fields are never listed.
 *
 * @param required ids of fields that are required right now
 * @param fileNames the names of the files added to an upload field
 */
export function buildReview(
	pages: LayoutPage[],
	fields: Record<string, FormField>,
	values: Record<string, unknown>,
	required: ReadonlySet<string>,
	fileNames: (fieldId: string) => string[],
): Review {
	const groups: ReviewGroup[] = []
	let skipped = 0
	pages.forEach((page, pageIndex) => {
		const items: ReviewItem[] = []
		for (const row of page.rows) {
			for (const cell of row.cells) {
				const field = Object.hasOwn(fields, cell.field) ? fields[cell.field] : undefined
				if (!field || !isInputType(field.type) || field.type === 'hidden') {
					continue
				}
				const label = (field.label ?? '').trim() || cell.field
				if (isUnanswered(field, values[cell.field])) {
					if (required.has(cell.field)) {
						items.push({ id: cell.field, label, text: '', missing: true })
					} else {
						skipped++
					}
					continue
				}
				const text = field.type === 'file' ? fileNames(cell.field).join(', ') : displayAnswer(field, values[cell.field])
				items.push({ id: cell.field, label, text, missing: false })
			}
		}
		if (items.length > 0) {
			groups.push({ pageIndex, title: page.title?.trim() || null, items })
		}
	})
	return { groups, skipped }
}
