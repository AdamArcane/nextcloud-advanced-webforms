/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PublicForm, SubmitResult } from '../types.ts'

import axios from '@nextcloud/axios'
import { toApiError, url } from './http.ts'

// Kept so existing imports of ApiError from this module keep working.
export { ApiError } from './http.ts'

export async function fetchPublicForm(token: string): Promise<PublicForm> {
	try {
		const response = await axios.get<PublicForm>(url('/public/' + encodeURIComponent(token)))
		return response.data
	} catch (error) {
		throw toApiError(error)
	}
}

export interface SubmitBody {
	versionId: number
	values: Record<string, unknown>
	fillToken: string
	hp: string
}

export async function submitPublicForm(token: string, body: SubmitBody): Promise<SubmitResult> {
	try {
		const response = await axios.post<SubmitResult>(url('/public/' + encodeURIComponent(token) + '/submit'), body)
		return response.data
	} catch (error) {
		throw toApiError(error)
	}
}
