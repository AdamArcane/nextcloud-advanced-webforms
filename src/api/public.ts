/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PublicForm, SubmitResult } from '../types.ts'
import type { PowChallenge } from '../captcha/pow.ts'

import axios from '@nextcloud/axios'
import { isEmbedded } from '../embedded.ts'
import { toApiError, url } from './http.ts'

// Kept so existing imports of ApiError from this module keep working.
export { ApiError } from './http.ts'

/**
 * The address of a call for one form. An embedded form uses the `embed` routes, which work without the
 * session cookie a browser will not send from a frame on another website.
 */
function formUrl(token: string, action: '' | '/submit' | '/upload'): string {
	return url('/public/' + encodeURIComponent(token) + (isEmbedded() ? '/embed' : '') + action)
}

export async function fetchPublicForm(token: string): Promise<PublicForm> {
	try {
		const response = await axios.get<PublicForm>(formUrl(token, ''))
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
	/** The answer to the spam check, when the form has one. */
	captcha?: string
}

export async function submitPublicForm(token: string, body: SubmitBody): Promise<SubmitResult> {
	try {
		const response = await axios.post<SubmitResult>(formUrl(token, '/submit'), body)
		return response.data
	} catch (error) {
		throw toApiError(error)
	}
}

export interface UploadedFile {
	id: string
	name: string
	size: number
}

/** Send one file for an upload field. The id it returns is what the field's answer holds. */
export async function uploadPublicFile(
	token: string,
	context: { versionId: number, fillToken: string },
	fieldId: string,
	file: File,
	onProgress: (fraction: number) => void,
): Promise<UploadedFile> {
	const body = new FormData()
	body.append('versionId', String(context.versionId))
	body.append('fieldId', fieldId)
	body.append('fillToken', context.fillToken)
	body.append('file', file)
	try {
		const response = await axios.post<UploadedFile>(formUrl(token, '/upload'), body, {
			onUploadProgress: (event) => onProgress(event.total ? event.loaded / event.total : 0),
		})
		return response.data
	} catch (error) {
		throw toApiError(error)
	}
}

/** A new challenge for the built-in spam check. */
export async function fetchCaptchaChallenge(): Promise<PowChallenge> {
	try {
		const response = await axios.get<PowChallenge>(url('/public/captcha/challenge'))
		return response.data
	} catch (error) {
		throw toApiError(error)
	}
}
