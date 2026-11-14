/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Delivery, DeliveryStatus } from '../types.ts'

import { t } from '@nextcloud/l10n'

export function statusLabel(status: DeliveryStatus): string {
	switch (status) {
		case 'success': return t('advancedforms', 'Sent')
		case 'failed': return t('advancedforms', 'Failed')
		case 'skipped': return t('advancedforms', 'Skipped')
		default: return t('advancedforms', 'Waiting')
	}
}

export function typeLabel(type: string): string {
	switch (type) {
		case 'email': return t('advancedforms', 'Email')
		case 'webhook': return t('advancedforms', 'Webhook')
		default: return type || t('advancedforms', 'Action')
	}
}

/**
 * Why a delivery did not go through, in words the owner can act on. The server
 * records short codes (lib/Actions/ActionRunner.php); anything unrecognised is
 * shown as it came.
 */
export function describeDeliveryError(error: string | null): string {
	if (error === null || error === '') {
		return ''
	}
	switch (error) {
		case 'no_recipient': return t('advancedforms', 'The respondent did not give a usable email address.')
		case 'no_account_email': return t('advancedforms', 'That Nextcloud account has no email address set.')
		case 'not_signed_in': return t('advancedforms', 'The respondent was not signed in, so there is no account to email.')
		case 'rate_limited': return t('advancedforms', 'Too many emails to respondents were sent for this form in the last hour.')
		case 'secret_missing': return t('advancedforms', 'The webhook has no signing secret. Generate a new one in the Actions tab.')
		case 'bad_url': return t('advancedforms', 'The webhook address is not valid.')
		case 'bad_recipient': return t('advancedforms', 'The email has no valid recipient set.')
		case 'action_missing': return t('advancedforms', 'This action was removed from the form.')
		case 'entry_deleted': return t('advancedforms', 'The response was deleted before this could be sent.')
		case 'unexpected_error': return t('advancedforms', 'Something unexpected went wrong. Check the Nextcloud log.')
		case 'unsupported_action': return t('advancedforms', 'This kind of action is not supported.')
	}
	const status = /^http_(\d{3})$/.exec(error)
	if (status) {
		return t('advancedforms', 'The receiver answered with HTTP {status}.', { status: status[1] })
	}
	if (error.startsWith('request_failed:')) {
		return t('advancedforms', 'The request could not be made:{detail}', { detail: error.slice('request_failed:'.length) })
	}
	if (error.startsWith('mail_failed:')) {
		return t('advancedforms', 'The mail server refused it:{detail}', { detail: error.slice('mail_failed:'.length) })
	}
	return error
}

/** Failed deliveries are the only ones that can be retried by hand. */
export function canRetry(delivery: Delivery): boolean {
	return delivery.status === 'failed'
}
