/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { TableFieldSpec, TablesColumn, TablesList } from '../builder/tablesMap.ts'

import axios from '@nextcloud/axios'
import { toApiError, url } from './http.ts'

/** The Nextcloud Tables tables the user may add rows to. `available` is false when Tables is off. */
export async function fetchTables(): Promise<TablesList> {
	try {
		return (await axios.get<TablesList>(url('/tables'))).data
	} catch (error) {
		throw toApiError(error)
	}
}

/** One table's columns, each with the kind of answer it takes. */
export async function fetchTableColumns(tableId: number): Promise<TablesColumn[]> {
	try {
		return (await axios.get<{ columns: TablesColumn[] }>(url('/tables/' + tableId + '/columns'))).data.columns
	} catch (error) {
		throw toApiError(error)
	}
}

export interface CreatedTable {
	tableId: number
	title: string
	/** Column id to source, ready to use as the action's mapping. */
	map: Record<string, string>
}

/** Create a table for the form: one optional column per field, then one for when it was submitted. */
export async function createTableFromForm(title: string, fields: TableFieldSpec[]): Promise<CreatedTable> {
	try {
		return (await axios.post<CreatedTable>(url('/tables/from-form'), { title, fields })).data
	} catch (error) {
		throw toApiError(error)
	}
}
