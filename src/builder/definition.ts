/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Pure edit operations on a form definition. Each returns a new definition
// and never mutates its input, so callers can keep the old one for undo.

import type { DraftDefinition, FormField } from '../types.ts'
import type { LayoutCell, LayoutRow, LayoutPage } from '../layout/visibleLayout.ts'
import type { Condition, Rule } from '../rules/types.ts'

/** Spans the schema allows (docs/SCHEMA.md section 5), in twelfths of a row. */
export const SPANS = [3, 4, 6, 8, 9, 12] as const
const ROW_WIDTH = 12

export interface FieldTypeInfo {
	type: string
	label: string
	/** Display types hold no value. */
	display?: boolean
}

export const FIELD_TYPES: readonly FieldTypeInfo[] = [
	{ type: 'text', label: 'Short text' },
	{ type: 'textarea', label: 'Long text' },
	{ type: 'email', label: 'Email' },
	{ type: 'phone', label: 'Phone' },
	{ type: 'number', label: 'Number' },
	{ type: 'select', label: 'Dropdown' },
	{ type: 'radio', label: 'Single choice' },
	{ type: 'checkboxes', label: 'Multiple choice' },
	{ type: 'checkbox', label: 'Checkbox' },
	{ type: 'date', label: 'Date' },
	{ type: 'time', label: 'Time' },
	{ type: 'heading', label: 'Heading', display: true },
	{ type: 'paragraph', label: 'Paragraph', display: true },
	{ type: 'divider', label: 'Divider', display: true },
]

export function fieldTypeInfo(type: string): FieldTypeInfo | undefined {
	return FIELD_TYPES.find((info) => info.type === type)
}

/** A new empty form definition. */
export function emptyDefinition(title: string): DraftDefinition {
	return {
		schemaVersion: 1,
		title,
		settings: {},
		fields: {},
		pages: [{ id: 'p_1', title: null, rows: [] }],
		rules: [],
		actions: [],
	}
}

/** Ids match `^[a-z][a-z0-9_]{2,31}$` and are never derived from labels (SCHEMA 4.1). */
export function randomId(prefix: string, taken: ReadonlySet<string> = new Set()): string {
	for (;;) {
		const id = prefix + '_' + Math.random().toString(36).slice(2, 8).padEnd(6, '0')
		if (!taken.has(id)) {
			return id
		}
	}
}

function allIds(definition: DraftDefinition): Set<string> {
	const ids = new Set<string>(Object.keys(definition.fields))
	for (const rule of definition.rules) {
		ids.add(rule.id)
	}
	for (const page of definition.pages) {
		ids.add(page.id)
		for (const row of page.rows) {
			ids.add(row.id)
			for (const cell of row.cells) {
				ids.add(cell.id)
			}
		}
	}
	return ids
}

/** A sensible starting field for a type. */
export function defaultField(type: string, optionValue: () => string): FormField {
	const label = fieldTypeInfo(type)?.label ?? type
	switch (type) {
		case 'heading':
			return { type, text: 'Heading', level: 2 }
		case 'paragraph':
			return { type, text: 'Some text.' }
		case 'divider':
			return { type }
		case 'select':
		case 'radio':
		case 'checkboxes':
			return {
				type,
				label,
				required: false,
				options: [
					{ value: optionValue(), label: 'Option 1' },
					{ value: optionValue(), label: 'Option 2' },
				],
			}
		default:
			return { type, label, required: false }
	}
}

/** The largest allowed span that fits in `space` twelfths, or null if none does. */
export function largestSpanWithin(space: number): number | null {
	let best: number | null = null
	for (const span of SPANS) {
		if (span <= space) {
			best = span
		}
	}
	return best
}

function rowsOf(definition: DraftDefinition, pageIndex = 0): LayoutRow[] {
	return definition.pages[pageIndex]?.rows ?? []
}

/** The most pages a form may have (lib/Forms/DefinitionValidator.php). */
export const MAX_PAGES = 20

function rowUsed(row: LayoutRow, ignoreCellId?: string): number {
	return row.cells.reduce((sum, cell) => (cell.id === ignoreCellId ? sum : sum + cell.span), 0)
}

/**
 * Deep copy of plain JSON data. structuredClone would be the obvious choice,
 * but it throws on Vue's reactive proxies, and the editor's draft lives in one.
 */
export function cloneJson<T>(value: T): T {
	return JSON.parse(JSON.stringify(value)) as T
}

function clone(definition: DraftDefinition): DraftDefinition {
	return cloneJson(definition)
}

export type Placement =
	/** A new row of its own at this row index (0 = top, rows.length = bottom) of a page (default the first). */
	| { kind: 'row', index: number, page?: number }
	/** At the end of an existing row, if there is room; otherwise in a new row below it. */
	| { kind: 'join', rowId: string }

export interface AddResult {
	definition: DraftDefinition
	fieldId: string
}

export function addField(definition: DraftDefinition, type: string, placement: Placement): AddResult {
	const next = clone(definition)
	const taken = allIds(next)
	const fieldId = randomId('f', taken)
	taken.add(fieldId)
	const cellId = randomId('c', taken)
	taken.add(cellId)
	const rowId = randomId('r', taken)
	const optionTaken = new Set<string>()
	next.fields[fieldId] = defaultField(type, () => {
		const value = randomId('opt', optionTaken)
		optionTaken.add(value)
		return value
	})

	let rows = rowsOf(next, placement.kind === 'row' ? placement.page ?? 0 : 0)
	if (placement.kind === 'join') {
		const joinPage = next.pages.find((page) => page.rows.some((row) => row.id === placement.rowId))
		rows = joinPage?.rows ?? rows
		const index = rows.findIndex((row) => row.id === placement.rowId)
		const row = rows[index]
		const span = row ? largestSpanWithin(ROW_WIDTH - rowUsed(row)) : null
		if (row && span !== null) {
			row.cells.push({ id: cellId, span, field: fieldId })
			return { definition: next, fieldId }
		}
		rows.splice(index + 1, 0, { id: rowId, cells: [{ id: cellId, span: ROW_WIDTH, field: fieldId }] })
		return { definition: next, fieldId }
	}

	const index = Math.max(0, Math.min(placement.index, rows.length))
	rows.splice(index, 0, { id: rowId, cells: [{ id: cellId, span: ROW_WIDTH, field: fieldId }] })
	return { definition: next, fieldId }
}

/** Replace a field's properties. Pass `undefined` for a key to remove it. */
export function updateField(definition: DraftDefinition, fieldId: string, patch: Partial<FormField>): DraftDefinition {
	if (!(fieldId in definition.fields)) {
		return definition
	}
	const next = clone(definition)
	const field = next.fields[fieldId] as Record<string, unknown>
	for (const [key, value] of Object.entries(patch)) {
		if (value === undefined) {
			delete field[key]
		} else {
			field[key] = value
		}
	}
	return next
}

/** Drop conditions that read `fieldId`. Returns null when nothing is left of the group. */
function pruneCondition(condition: Condition, fieldId: string): Condition | null {
	if ('field' in condition) {
		return condition.field === fieldId ? null : condition
	}
	const isAll = 'all' in condition
	const children = (isAll ? condition.all : condition.any)
		.map((child) => pruneCondition(child, fieldId))
		.filter((child): child is Condition => child !== null)
	if (children.length === 0) {
		return null
	}
	return isAll ? { all: children } : { any: children }
}

function pruneRules(rules: Rule[], fieldId: string): Rule[] {
	const result: Rule[] = []
	for (const rule of rules) {
		const targets = rule.targets.filter((target) => target !== fieldId)
		const when = pruneCondition(rule.when, fieldId)
		if (targets.length > 0 && when !== null) {
			result.push({ ...rule, targets, when })
		}
	}
	return result
}

/**
 * Remove a field, its cell, any row left empty, and every rule target or
 * condition that mentioned it. A rule left with no targets or no conditions is
 * dropped, since it could no longer do anything. The server retires the id.
 */
export function removeField(definition: DraftDefinition, fieldId: string): DraftDefinition {
	const next = clone(definition)
	delete next.fields[fieldId]
	for (const page of next.pages) {
		for (const row of page.rows) {
			row.cells = row.cells.filter((cell) => cell.field !== fieldId)
		}
		page.rows = page.rows.filter((row) => row.cells.length > 0)
	}
	next.rules = pruneRules(next.rules, fieldId)
	return next
}

export interface CellLocation {
	pageIndex: number
	rowIndex: number
	cellIndex: number
	row: LayoutRow
	cell: LayoutCell
}

export function locateField(definition: DraftDefinition, fieldId: string): CellLocation | null {
	for (let pageIndex = 0; pageIndex < definition.pages.length; pageIndex++) {
		const rows = rowsOf(definition, pageIndex)
		for (let rowIndex = 0; rowIndex < rows.length; rowIndex++) {
			const cellIndex = rows[rowIndex].cells.findIndex((cell) => cell.field === fieldId)
			if (cellIndex !== -1) {
				return { pageIndex, rowIndex, cellIndex, row: rows[rowIndex], cell: rows[rowIndex].cells[cellIndex] }
			}
		}
	}
	return null
}

export type Move = 'rowUp' | 'rowDown' | 'left' | 'right' | 'joinAbove' | 'ownRow'

/** Whether a move would do anything, for enabling buttons. */
export function canMove(definition: DraftDefinition, fieldId: string, move: Move): boolean {
	const at = locateField(definition, fieldId)
	if (!at) {
		return false
	}
	const rows = rowsOf(definition, at.pageIndex)
	switch (move) {
		case 'rowUp':
			return at.rowIndex > 0
		case 'rowDown':
			return at.rowIndex < rows.length - 1
		case 'left':
			return at.cellIndex > 0
		case 'right':
			return at.cellIndex < at.row.cells.length - 1
		case 'joinAbove':
			return at.rowIndex > 0 && largestSpanWithin(ROW_WIDTH - rowUsed(rows[at.rowIndex - 1])) !== null
		case 'ownRow':
			return at.row.cells.length > 1
	}
}

/**
 * Move a field in the grid.
 * - `rowUp` and `rowDown` move the whole row the field is in.
 * - `left` and `right` reorder within the row.
 * - `joinAbove` moves the field to the end of the previous row (shrinking it to fit).
 * - `ownRow` splits the field out into a full-width row of its own below.
 */
export function moveField(definition: DraftDefinition, fieldId: string, move: Move): DraftDefinition {
	if (!canMove(definition, fieldId, move)) {
		return definition
	}
	const next = clone(definition)
	const at = locateField(next, fieldId)!
	const rows = rowsOf(next, at.pageIndex)
	switch (move) {
		case 'rowUp':
		case 'rowDown': {
			const target = at.rowIndex + (move === 'rowUp' ? -1 : 1)
			;[rows[at.rowIndex], rows[target]] = [rows[target], rows[at.rowIndex]]
			break
		}
		case 'left':
		case 'right': {
			const target = at.cellIndex + (move === 'left' ? -1 : 1)
			const cells = at.row.cells
			;[cells[at.cellIndex], cells[target]] = [cells[target], cells[at.cellIndex]]
			break
		}
		case 'joinAbove': {
			const previous = rows[at.rowIndex - 1]
			const span = Math.min(at.cell.span, largestSpanWithin(ROW_WIDTH - rowUsed(previous))!)
			at.row.cells.splice(at.cellIndex, 1)
			previous.cells.push({ ...at.cell, span: largestSpanWithin(span) ?? span })
			if (at.row.cells.length === 0) {
				rows.splice(at.rowIndex, 1)
			}
			break
		}
		case 'ownRow': {
			at.row.cells.splice(at.cellIndex, 1)
			const rowId = randomId('r', allIds(next))
			rows.splice(at.rowIndex + 1, 0, { id: rowId, cells: [{ ...at.cell, span: ROW_WIDTH }] })
			break
		}
	}
	return next
}

/** Spans a field's cell may take without overflowing its row. */
export function allowedSpans(definition: DraftDefinition, fieldId: string): number[] {
	const at = locateField(definition, fieldId)
	if (!at) {
		return []
	}
	const free = ROW_WIDTH - rowUsed(at.row, at.cell.id)
	return SPANS.filter((span) => span <= free)
}

export function setSpan(definition: DraftDefinition, fieldId: string, span: number): DraftDefinition {
	if (!allowedSpans(definition, fieldId).includes(span)) {
		return definition
	}
	const next = clone(definition)
	locateField(next, fieldId)!.cell.span = span
	return next
}

/** Rules that act on, or read, a field: used for the ⚡ badge on cards. */
export function rulesTouching(definition: DraftDefinition, fieldId: string): Rule[] {
	return definition.rules.filter((rule) => rule.targets.includes(fieldId))
}

/** Field ids in layout order (top to bottom, left to right). */
export function fieldOrder(definition: DraftDefinition): string[] {
	return definition.pages.flatMap((page) => page.rows.flatMap((row) => row.cells.map((cell) => cell.field)))
}

/** Add an empty page at the end, or at `index`. */
export function addPage(definition: DraftDefinition, index = definition.pages.length): DraftDefinition {
	if (definition.pages.length >= MAX_PAGES) {
		return definition
	}
	const next = clone(definition)
	const page: LayoutPage = { id: randomId('p', allIds(next)), title: null, rows: [] }
	next.pages.splice(Math.max(0, Math.min(index, next.pages.length)), 0, page)
	return next
}

/** Set a page's title. An empty title is stored as null. */
export function setPageTitle(definition: DraftDefinition, pageId: string, title: string): DraftDefinition {
	const next = clone(definition)
	const page = next.pages.find((candidate) => candidate.id === pageId)
	if (!page) {
		return definition
	}
	page.title = title.trim() === '' ? null : title
	return next
}

/** Move a page one step earlier (-1) or later (1). */
export function movePage(definition: DraftDefinition, pageId: string, direction: -1 | 1): DraftDefinition {
	const from = definition.pages.findIndex((page) => page.id === pageId)
	const to = from + direction
	if (from === -1 || to < 0 || to >= definition.pages.length) {
		return definition
	}
	const next = clone(definition)
	;[next.pages[from], next.pages[to]] = [next.pages[to], next.pages[from]]
	return next
}

/**
 * Remove an empty page. A page that still has fields is left alone: the owner moves
 * or deletes the fields first, so nothing disappears by accident. The last page stays.
 */
export function removePage(definition: DraftDefinition, pageId: string): DraftDefinition {
	const page = definition.pages.find((candidate) => candidate.id === pageId)
	if (!page || page.rows.length > 0 || definition.pages.length <= 1) {
		return definition
	}
	const next = clone(definition)
	next.pages = next.pages.filter((candidate) => candidate.id !== pageId)
	return next
}

/** Move a field to the end of another page, in a full-width row of its own. */
export function moveFieldToPage(definition: DraftDefinition, fieldId: string, pageIndex: number): DraftDefinition {
	const at = locateField(definition, fieldId)
	if (!at || at.pageIndex === pageIndex || pageIndex < 0 || pageIndex >= definition.pages.length) {
		return definition
	}
	const next = clone(definition)
	const from = locateField(next, fieldId)!
	from.row.cells.splice(from.cellIndex, 1)
	next.pages[from.pageIndex].rows = next.pages[from.pageIndex].rows.filter((row) => row.cells.length > 0)
	const rowId = randomId('r', allIds(next))
	next.pages[pageIndex].rows.push({ id: rowId, cells: [{ ...from.cell, span: ROW_WIDTH }] })
	return next
}
