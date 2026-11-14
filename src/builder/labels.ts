/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
