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
		case 'eq': return t('advancedforms', 'is')
		case 'neq': return t('advancedforms', 'is not')
		case 'contains': return t('advancedforms', 'includes')
		case 'not_contains': return t('advancedforms', 'does not include')
		case 'contains_any': return t('advancedforms', 'includes any of')
		case 'contains_all': return t('advancedforms', 'includes all of')
		case 'contains_none': return t('advancedforms', 'includes none of')
		case 'in': return t('advancedforms', 'is one of')
		case 'not_in': return t('advancedforms', 'is none of')
		case 'empty': return t('advancedforms', 'is empty')
		case 'not_empty': return t('advancedforms', 'is filled in')
		case 'gt': return temporal ? t('advancedforms', 'is after') : t('advancedforms', 'is greater than')
		case 'gte': return temporal ? t('advancedforms', 'is on or after') : t('advancedforms', 'is at least')
		case 'lt': return temporal ? t('advancedforms', 'is before') : t('advancedforms', 'is less than')
		case 'lte': return temporal ? t('advancedforms', 'is on or before') : t('advancedforms', 'is at most')
		default: return op
	}
}

export function effectLabel(effect: RuleEffect): string {
	switch (effect) {
		case 'show': return t('advancedforms', 'Show')
		case 'hide': return t('advancedforms', 'Hide')
		default: return t('advancedforms', 'Require')
	}
}

export function actionLabel(type: ActionType): string {
	switch (type) {
		case 'message': return t('advancedforms', 'Show a message')
		case 'redirect': return t('advancedforms', 'Redirect')
		case 'email': return t('advancedforms', 'Send an email')
		default: return t('advancedforms', 'Call a webhook')
	}
}

export function actionHint(type: ActionType): string {
	switch (type) {
		case 'message': return t('advancedforms', 'Thank the respondent on the page after they send the form.')
		case 'redirect': return t('advancedforms', 'Send the respondent to another page after they send the form.')
		case 'email': return t('advancedforms', 'Email you, or the respondent, a copy of the answers.')
		default: return t('advancedforms', 'Send the answers to another system, such as n8n or Zapier.')
	}
}
