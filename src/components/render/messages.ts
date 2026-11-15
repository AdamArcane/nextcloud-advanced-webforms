/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

/** Message for a server validation code. Codes are in docs/SCHEMA.md section 10. */
export function messageFor(code: string): string {
	switch (code) {
		case 'required':
			return t('webforms', 'This field is required.')
		case 'invalid_email':
			return t('webforms', 'Enter a valid email address.')
		case 'invalid_url':
			return t('webforms', 'Enter a valid web address, such as https://example.com.')
		case 'invalid_phone':
			return t('webforms', 'Enter a valid phone number.')
		case 'invalid_number':
			return t('webforms', 'Enter a valid number.')
		case 'below_min':
			return t('webforms', 'This number is too small.')
		case 'above_max':
			return t('webforms', 'This number is too large.')
		case 'not_step':
			return t('webforms', 'This number is not an allowed value.')
		case 'invalid_date':
			return t('webforms', 'Enter a valid date.')
		case 'invalid_time':
			return t('webforms', 'Enter a valid time.')
		case 'too_long':
			return t('webforms', 'This text is too long.')
		case 'too_short':
			return t('webforms', 'This text is too short.')
		case 'date_too_early':
			return t('webforms', 'This date is too early.')
		case 'date_too_late':
			return t('webforms', 'This date is too late.')
		case 'time_too_early':
			return t('webforms', 'This time is too early.')
		case 'time_too_late':
			return t('webforms', 'This time is too late.')
		case 'too_few':
			return t('webforms', 'Select more options.')
		case 'too_many':
			return t('webforms', 'Select fewer options.')
		case 'invalid_option':
		case 'duplicate_option':
			return t('webforms', 'Choose one of the available options.')
		case 'token_too_fast':
			return t('webforms', 'That was very quick. Please check your answers and send again.')
		case 'token_expired':
		case 'token_invalid':
			return t('webforms', 'This page was open for too long. Please send again.')
		case 'captcha_failed':
			return t('webforms', 'The spam check was not passed. Please try again.')
		case 'too_many_files':
			return t('webforms', 'You added too many files.')
		case 'duplicate_file':
		case 'upload_missing':
			return t('webforms', 'One of the files is no longer available. Remove it and add it again.')
		case 'upload_type_not_allowed':
			return t('webforms', 'This kind of file is not accepted.')
		case 'upload_too_large':
			return t('webforms', 'This file is too large.')
		case 'upload_empty':
			return t('webforms', 'This file is empty.')
		case 'upload_bad_name':
			return t('webforms', 'This file name is not valid.')
		case 'upload_login_required':
			return t('webforms', 'Sign in to upload files.')
		case 'upload_busy':
			return t('webforms', 'Too many files are waiting right now. Try again later.')
		case 'upload_destination_unavailable':
		case 'upload_anonymous_not_allowed':
		case 'upload_store_failed':
		case 'upload_owner_storage_full':
			return t('webforms', 'The file could not be saved. Please try again later or contact the form owner.')
		case 'upload_failed':
			return t('webforms', 'The upload did not work. Please try again.')
		case 'already_submitted':
			return t('webforms', 'You have already submitted this form.')
		default:
			return t('webforms', 'This answer is not valid.')
	}
}
