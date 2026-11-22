/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { CoreImportSkipped } from './api/forms.ts'

import { n, t } from '@nextcloud/l10n'

/**
 * What a line of the import report means, in the person's language. The server sends a code and an English
 * sentence; the sentence is only the fallback for a code this version of the screen does not know.
 */
export function describeSkipped(item: CoreImportSkipped): string {
	const question = item.label === ''
		? t('webforms', 'question {id}', { id: item.ref })
		: '“' + item.label + '”'

	switch (item.code) {
		case 'access_not_carried':
			return t('webforms', 'Who can open the form and how it is shared were not copied. Set this before you publish.')
		case 'confirmation_email':
			return t('webforms', 'The confirmation email was not copied. Add an email action to the draft.')
		case 'anonymous_form':
			return t('webforms', 'The form hid who answered, so the responses have no respondent.')
		case 'window_invalid':
			return t('webforms', 'The opening time was not before the closing time, so only the closing time was kept.')
		case 'text_cut':
			return t('webforms', 'The description was too long and was cut.')
		case 'too_many_fields':
			return t('webforms', 'Left out because a form holds at most 100 fields: {question}.', { question })
		case 'too_many_options':
			return t('webforms', '{question} has too many options and was left out.', { question })
		case 'ranking_unsupported':
			return t('webforms', 'Ranking has no equivalent, so {question} and its answers were left out.', { question })
		case 'time_range':
			return t('webforms', 'A time range has no equivalent, so {question} was left out.', { question })
		case 'grid_numbers':
			return t('webforms', 'A grid of numbers has no equivalent, so {question} was left out.', { question })
		case 'grid_too_large':
			return t('webforms', 'The grid {question} has too many rows or columns and was left out.', { question })
		case 'unsupported_type':
			return t('webforms', '{question} is of a type that has no equivalent and was left out.', { question })
		case 'no_options':
			return t('webforms', '{question} has no options and was left out.', { question })
		case 'allow_other_answer':
			return t('webforms', '{question} allowed an "other" answer. That has no equivalent, so those answers are not imported.', { question })
		case 'shuffle_options':
			return t('webforms', '{question} shuffled its options. That has no equivalent.', { question })
		case 'validation_regex':
			return t('webforms', '{question} checked the answer against a pattern. That has no equivalent, so it is a plain text field.', { question })
		case 'conditional_not_carried':
			return t('webforms', '{question} was only shown after a certain answer. It is now always shown and not required.', { question })
		case 'files_not_carried':
			return item.scope === 'answer'
				? n('webforms', '{count} file uploaded to {question} stays in the Forms app and was not copied.', '{count} files uploaded to {question} stay in the Forms app and were not copied.', item.count, { count: item.count, question })
				: t('webforms', '{question} was kept, but its uploaded files stay in the Forms app and are not copied.', { question })
		case 'responses_not_imported':
			return t('webforms', 'The responses were not imported because the form could not be published. Fix the draft, then import again.')
		case 'question_not_carried':
		case 'answer_invalid':
		case 'answer_out_of_range':
		case 'answer_not_an_option':
			return n('webforms', '{count} answer to {question} was not imported.', '{count} answers to {question} were not imported.', item.count, { count: item.count, question })
		default:
			return item.detail
	}
}
