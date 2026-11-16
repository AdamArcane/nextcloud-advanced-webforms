/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ActionType } from '../types.ts'
import type { RuleEffect } from '../rules/types.ts'

import { t } from '@nextcloud/l10n'

/** Wording for an operator, adjusted for dates and times where "greater" reads oddly. */
export function operatorLabel(op: string, type: string): string {
	const temporal = type === 'date' || type === 'time'
	switch (op) {
		case 'eq': return t('webforms', 'is')
		case 'neq': return t('webforms', 'is not')
		case 'contains': return t('webforms', 'includes')
		case 'not_contains': return t('webforms', 'does not include')
		case 'contains_any': return t('webforms', 'includes any of')
		case 'contains_all': return t('webforms', 'includes all of')
		case 'contains_none': return t('webforms', 'includes none of')
		case 'in': return t('webforms', 'is one of')
		case 'not_in': return t('webforms', 'is none of')
		case 'empty': return t('webforms', 'is empty')
		case 'not_empty': return t('webforms', 'is filled in')
		case 'gt': return temporal ? t('webforms', 'is after') : t('webforms', 'is greater than')
		case 'gte': return temporal ? t('webforms', 'is on or after') : t('webforms', 'is at least')
		case 'lt': return temporal ? t('webforms', 'is before') : t('webforms', 'is less than')
		case 'lte': return temporal ? t('webforms', 'is on or before') : t('webforms', 'is at most')
		default: return op
	}
}

export function effectLabel(effect: RuleEffect): string {
	switch (effect) {
		case 'show': return t('webforms', 'Show')
		case 'hide': return t('webforms', 'Hide')
		default: return t('webforms', 'Require')
	}
}

export function actionLabel(type: ActionType): string {
	switch (type) {
		case 'message': return t('webforms', 'Show a message')
		case 'redirect': return t('webforms', 'Redirect')
		case 'email': return t('webforms', 'Send an email')
		case 'tables': return t('webforms', 'Add a row to Tables')
		case 'files': return t('webforms', 'Add a line to a CSV file')
		default: return t('webforms', 'Call a webhook')
	}
}

export function actionHint(type: ActionType): string {
	switch (type) {
		case 'message': return t('webforms', 'Thank the respondent on the page after they send the form.')
		case 'redirect': return t('webforms', 'Send the respondent to another page after they send the form.')
		case 'email': return t('webforms', 'Email you, or the respondent, a copy of the answers.')
		case 'tables': return t('webforms', 'Save each response as a row in a Nextcloud Tables table.')
		case 'files': return t('webforms', 'Keep a spreadsheet of every response in your Files.')
		default: return t('webforms', 'Send the answers to another system, such as n8n or Zapier.')
	}
}
