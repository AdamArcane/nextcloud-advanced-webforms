/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Downloading and bulk-deleting responses (docs/SCHEMA.md section 17.1).

import type { EntryFilterState } from './filter.ts'

import { filterParams } from './filter.ts'

export type ExportFormat = 'csv' | 'xlsx'

/** The server takes at most this many selected ids in one download or one bulk delete. */
export const MAX_SELECTED = 500

export interface ExportOptions {
	format?: ExportFormat
	/** The responses a person ticked. When given, the filter is not used. */
	ids?: number[]
	filter?: EntryFilterState
	/** Only these questions. Leave out for all of them. */
	columns?: string[]
}

/** The query string for a download, empty when it is everything as CSV. */
export function exportQuery(options: ExportOptions = {}): string {
	const params = new URLSearchParams()
	if (options.format === 'xlsx') {
		params.set('format', 'xlsx')
	}
	if (options.ids !== undefined && options.ids.length > 0) {
		params.set('ids', options.ids.join(','))
	} else if (options.filter !== undefined) {
		for (const [key, value] of Object.entries(filterParams(options.filter))) {
			params.set(key, value)
		}
	}
	if (options.columns !== undefined) {
		params.set('columns', options.columns.join(','))
	}
	return params.toString()
}

export type BulkDeleteRequest = { ids: number[] } | { filter: EntryFilterState, expected: number }

/** The JSON body for a bulk delete. A filter always travels with the number the person confirmed. */
export function bulkDeleteBody(request: BulkDeleteRequest): Record<string, unknown> {
	if ('ids' in request) {
		return { ids: request.ids }
	}
	const { sort: _sort, ...filter } = filterParams(request.filter)
	return { filter, expected: request.expected }
}

/** What a download covers, for the menu: the ticked responses, what the filter matches, or everything. */
export type ExportScope = 'selected' | 'matching' | 'all'

export function exportScope(selectedCount: number, allMatching: boolean, filtered: boolean): ExportScope {
	if (selectedCount > 0 && !allMatching) {
		return 'selected'
	}
	return allMatching || filtered ? 'matching' : 'all'
}
