/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DefinitionError } from '../types.ts'

import { t } from '@nextcloud/l10n'

/** Human wording for a definition error code (lib/Forms/DefinitionValidator.php). */
export function describeError(code: string): string {
	switch (code) {
		case 'field_label_invalid':
			return t('advancedforms', 'Give this field a label.')
		case 'field_text_invalid':
			return t('advancedforms', 'Add some text.')
		case 'options_invalid':
			return t('advancedforms', 'Add at least one option.')
		case 'option_label_invalid':
			return t('advancedforms', 'Every option needs a label.')
		case 'option_duplicate_value':
			return t('advancedforms', 'Two options share the same value.')
		case 'field_limit_invalid':
			return t('advancedforms', 'One of the limits is not valid.')
		case 'field_default_invalid':
			return t('advancedforms', 'The default value is not valid.')
		case 'field_placeholder_invalid':
			return t('advancedforms', 'The placeholder is too long.')
		case 'field_description_invalid':
			return t('advancedforms', 'The help text is too long.')
		case 'title_invalid':
			return t('advancedforms', 'The form needs a title.')
		case 'too_many_fields':
			return t('advancedforms', 'This form has too many fields.')
		case 'field_id_retired':
			return t('advancedforms', 'This field id was used by a deleted field.')
		case 'too_large':
			return t('advancedforms', 'This form is too large.')
		default:
			return t('advancedforms', 'This is not valid ({code}).', { code })
	}
}

/** The field id an error path points into, or null for form-level errors. */
export function errorFieldId(error: DefinitionError): string | null {
	const match = /^fields\.([^.[\]]+)/.exec(error.path)
	return match ? match[1] : null
}

/** Errors grouped by field id; form-level errors go under the empty key. */
export function groupErrors(errors: readonly DefinitionError[]): Map<string, DefinitionError[]> {
	const groups = new Map<string, DefinitionError[]>()
	for (const error of errors) {
		const key = errorFieldId(error) ?? ''
		groups.set(key, [...(groups.get(key) ?? []), error])
	}
	return groups
}
