/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FieldError, PublicForm, SubmitResult } from '../types.ts'

import axios, { isAxiosError } from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** A failed API call, reduced to what the UI needs. */
export class ApiError extends Error {
	constructor(
		public readonly status: number,
		public readonly code: string,
		public readonly errors: FieldError[] = [],
		public readonly serverMessage: string | null = null,
	) {
		super(code)
		this.name = 'ApiError'
	}
}

function toApiError(error: unknown): ApiError {
	if (isAxiosError(error) && error.response) {
		const body = (error.response.data ?? {}) as { error?: string, errors?: FieldError[], message?: string }
		return new ApiError(error.response.status, body.error ?? 'failed', body.errors ?? [], body.message ?? null)
	}
	return new ApiError(0, 'network')
}

function url(path: string): string {
	return generateUrl('/apps/advancedforms/api/v1' + path)
}

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
