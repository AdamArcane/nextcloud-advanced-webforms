/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FieldError } from '../types.ts'

import { isAxiosError } from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** A failed API call, reduced to what the UI needs. */
export class ApiError extends Error {
	constructor(
		public readonly status: number,
		public readonly code: string,
		public readonly errors: FieldError[] = [],
		public readonly serverMessage: string | null = null,
		/** The rest of the response body, for errors that carry numbers (like how many responses would be deleted). */
		public readonly data: Record<string, unknown> = {},
	) {
		super(code)
		this.name = 'ApiError'
	}
}

export function toApiError(error: unknown): ApiError {
	if (isAxiosError(error) && error.response) {
		const body = (error.response.data ?? {}) as { error?: string, errors?: FieldError[], message?: string } & Record<string, unknown>
		return new ApiError(error.response.status, body.error ?? 'failed', body.errors ?? [], body.message ?? null, body)
	}
	return new ApiError(0, 'network')
}

export function url(path: string): string {
	return generateUrl('/apps/webforms/api/v1' + path)
}
