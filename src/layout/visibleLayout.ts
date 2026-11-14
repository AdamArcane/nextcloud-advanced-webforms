/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export interface LayoutCell {
	id: string
	span: number
	field: string
}

export interface LayoutRow {
	id: string
	cells: LayoutCell[]
}

export interface LayoutPage {
	id: string
	title: string | null
	rows: LayoutRow[]
}

/**
 * Rows to render for the fields that are currently visible
 * (docs/SCHEMA.md section 5). Hidden cells are removed, the remaining cells
 * keep their own spans (so the layout does not shift as answers change), and
 * a row with no visible cells disappears.
 */
export function visibleLayout(pages: LayoutPage[], visible: ReadonlySet<string>): LayoutRow[] {
	return visibleRows(pages[0], visible)
}

/** The pages a respondent steps through: pages with nothing visible are skipped. */
export function visiblePages(pages: LayoutPage[], visible: ReadonlySet<string>): LayoutPage[] {
	return pages
		.map((page) => ({ ...page, rows: visibleRows(page, visible) }))
		.filter((page) => page.rows.length > 0)
}

function visibleRows(page: LayoutPage | undefined, visible: ReadonlySet<string>): LayoutRow[] {
	const rows: LayoutRow[] = []
	for (const row of page?.rows ?? []) {
		const cells = row.cells.filter((cell) => visible.has(cell.field))
		if (cells.length > 0) {
			rows.push({ id: row.id, cells })
		}
	}
	return rows
}
