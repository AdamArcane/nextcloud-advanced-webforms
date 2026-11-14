/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { toApiError, url } from './http.ts'

export type CaptchaProviderId = 'none' | 'builtin' | 'turnstile' | 'hcaptcha'

export interface CaptchaAdminSettings {
	provider: CaptchaProviderId
	siteKey: string
	/** Whether a secret is stored. The secret itself is never sent back. */
	secretSet: boolean
}

export async function fetchCaptchaSettings(): Promise<CaptchaAdminSettings> {
	try {
		return (await axios.get<CaptchaAdminSettings>(url('/admin/captcha'))).data
	} catch (error) {
		throw toApiError(error)
	}
}

export async function saveCaptchaSettings(body: { provider: CaptchaProviderId, siteKey: string, secret: string | null }): Promise<CaptchaAdminSettings> {
	try {
		return (await axios.put<CaptchaAdminSettings>(url('/admin/captcha'), body)).data
	} catch (error) {
		throw toApiError(error)
	}
}
