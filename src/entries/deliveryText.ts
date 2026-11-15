/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Delivery, DeliveryStatus } from '../types.ts'

import { t } from '@nextcloud/l10n'

export function statusLabel(status: DeliveryStatus): string {
	switch (status) {
		case 'success': return t('webforms', 'Sent')
		case 'failed': return t('webforms', 'Failed')
		case 'skipped': return t('webforms', 'Skipped')
		default: return t('webforms', 'Waiting')
	}
}

export function typeLabel(type: string): string {
	switch (type) {
		case 'email': return t('webforms', 'Email')
		case 'webhook': return t('webforms', 'Webhook')
		default: return type || t('webforms', 'Action')
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
		case 'no_recipient': return t('webforms', 'The respondent did not give a usable email address.')
		case 'no_account_email': return t('webforms', 'That Nextcloud account has no email address set.')
		case 'not_signed_in': return t('webforms', 'The respondent was not signed in, so there is no account to email.')
		case 'rate_limited': return t('webforms', 'Too many emails to respondents were sent for this form in the last hour.')
		case 'secret_missing': return t('webforms', 'The webhook has no signing secret. Generate a new one in the Actions tab.')
		case 'bad_url': return t('webforms', 'The webhook address is not valid.')
		case 'bad_recipient': return t('webforms', 'The email has no valid recipient set.')
		case 'action_missing': return t('webforms', 'This action was removed from the form.')
		case 'entry_deleted': return t('webforms', 'The response was deleted before this could be sent.')
		case 'unexpected_error': return t('webforms', 'Something unexpected went wrong. Check the Nextcloud log.')
		case 'unsupported_action': return t('webforms', 'This kind of action is not supported.')
	}
	const status = /^http_(\d{3})$/.exec(error)
	if (status) {
		return t('webforms', 'The receiver answered with HTTP {status}.', { status: status[1] })
	}
	if (error.startsWith('request_failed:')) {
		return t('webforms', 'The request could not be made:{detail}', { detail: error.slice('request_failed:'.length) })
	}
	if (error.startsWith('mail_failed:')) {
		return t('webforms', 'The mail server refused it:{detail}', { detail: error.slice('mail_failed:'.length) })
	}
	return error
}

/** Failed deliveries are the only ones that can be retried by hand. */
export function canRetry(delivery: Delivery): boolean {
	return delivery.status === 'failed'
}
