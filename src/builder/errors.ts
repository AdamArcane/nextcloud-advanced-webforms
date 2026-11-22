/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DefinitionError, DraftDefinition } from '../types.ts'

import { t } from '@nextcloud/l10n'

/** Human wording for a definition error code (lib/Forms/DefinitionValidator.php). */
export function describeError(code: string): string {
	switch (code) {
		case 'field_label_invalid':
			return t('webforms', 'Give this field a label.')
		case 'field_image_invalid':
			return t('webforms', 'Choose a picture from your Files (PNG, JPEG, GIF or WebP), or give an address that starts with https://.')
		case 'field_image_missing':
			return t('webforms', 'This picture is not in your Files, or it is larger than 10 MB.')
		case 'field_text_invalid':
			return t('webforms', 'Add some text.')
		case 'options_invalid':
			return t('webforms', 'Add at least one option.')
		case 'option_label_invalid':
			return t('webforms', 'Every option needs a label.')
		case 'option_duplicate_value':
			return t('webforms', 'Two options share the same value.')
		case 'field_limit_invalid':
			return t('webforms', 'One of the limits is not valid.')
		case 'field_default_invalid':
			return t('webforms', 'The default value is not valid.')
		case 'field_placeholder_invalid':
			return t('webforms', 'The placeholder is too long.')
		case 'field_description_invalid':
			return t('webforms', 'The help text is too long.')
		case 'form_archived':
			return t('webforms', 'This form is archived. Restore it first.')
		case 'window_invalid':
			return t('webforms', 'The closing time must be after the opening time.')
		case 'title_invalid':
			return t('webforms', 'The form needs a title.')
		case 'too_many_fields':
			return t('webforms', 'This form has too many fields.')
		case 'field_id_retired':
			return t('webforms', 'This field id was used by a deleted field.')
		case 'rule_no_targets':
		case 'rule_unknown_target':
			return t('webforms', 'This rule does not point at a field.')
		case 'rule_require_on_display_field':
			return t('webforms', 'Only input fields can be required.')
		case 'rules_cycle':
			return t('webforms', 'Two fields depend on each other. Change one of the conditions.')
		case 'cond_unknown_field':
			return t('webforms', 'A condition uses a field that no longer exists.')
		case 'cond_source_not_input':
			return t('webforms', 'A condition reads a field that has no answer.')
		case 'cond_bad_operator':
			return t('webforms', 'A condition uses a comparison this field does not support.')
		case 'cond_bad_value':
			return t('webforms', 'A condition needs a valid value to compare against.')
		case 'cond_bad_shape':
		case 'cond_too_deep':
			return t('webforms', 'A group of conditions is empty or nested too deeply.')
		case 'action_bad_recipient':
			return t('webforms', 'Choose who should receive this email.')
		case 'action_mail_locked':
			return t('webforms', 'The administrator does not allow forms to set their own reply-to address or sender name. Remove it to publish.')
		case 'action_from_name_invalid':
			return t('webforms', 'The sender name must be one line of at most 100 characters.')
		case 'action_bad_reply_to':
			return t('webforms', 'The reply-to address is not a valid email address or email field.')
		case 'action_text_invalid':
			return t('webforms', 'Fill in all the text for this action.')
		case 'action_bad_url':
			return t('webforms', 'Enter a full address starting with https://.')
		case 'action_type_disabled':
			return t('webforms', 'This kind of action is switched off on this server. Remove it or ask an administrator.')
		case 'policy_too_many_actions':
			return t('webforms', 'This server allows fewer actions on a form. Remove some.')
		case 'create_not_allowed':
			return t('webforms', 'You are not allowed to create forms on this server. Ask an administrator.')
		case 'action_bad_table':
			return t('webforms', 'Pick the Tables table that should get the rows.')
		case 'action_bad_table_map':
			return t('webforms', 'Choose which answer goes in at least one column.')
		case 'action_bad_file_path':
			return t('webforms', 'Enter a file path in your Files that ends in .csv, like Webforms/Responses.csv.')
		case 'files_not_writable':
			return t('webforms', 'You cannot change that file or create it there. Choose a folder you can write to.')
		case 'tables_unavailable':
			return t('webforms', 'The Tables app is not available on this server.')
		case 'tables_not_allowed':
			return t('webforms', 'You are not allowed to add rows to that table.')
		case 'tables_not_found':
			return t('webforms', 'That table no longer exists.')
		case 'tables_column_missing':
			return t('webforms', 'A mapped column no longer exists in the table.')
		case 'tables_type_mismatch':
			return t('webforms', 'An answer does not fit its column. Choose a different answer or column.')
		case 'tables_no_fields':
			return t('webforms', 'Add some questions to the form first, there is nothing to store yet.')
		case 'tables_forbidden':
			return t('webforms', 'You are not allowed to use that table.')
		case 'action_unknown_placeholder':
			return t('webforms', 'The text uses a field that does not exist.')
		case 'action_bad_secret':
			return t('webforms', 'The signing secret is not valid. Generate a new one.')
		case 'too_many_actions':
			return t('webforms', 'This form has too many actions.')
		case 'rule_target_hidden_field':
			return t('webforms', 'A hidden value is never shown, so a rule cannot show, hide or require it.')
		case 'field_upload_invalid':
			return t('webforms', 'One of the upload settings is not valid.')
		case 'field_upload_folder_invalid':
			return t('webforms', 'The upload folder is not valid. Use a folder path like Documents/Applications.')
		case 'field_upload_accept_invalid':
			return t('webforms', 'Choose which kinds of files this field accepts.')
		case 'field_upload_type_denied':
			return t('webforms', 'Programs, scripts and web pages can never be uploaded.')
		case 'upload_destination_unavailable':
			return t('webforms', 'You cannot save files to this folder. Choose a folder you can write to.')
		case 'upload_anonymous_not_allowed':
			return t('webforms', 'You are not allowed to let anyone upload into your files. Limit this field to signed-in users, or keep the files in the app.')
		case 'template_limit':
			return t('webforms', 'You have reached the limit of saved templates. Delete one first.')
		case 'import_not_a_form':
			return t('webforms', 'This file is not an Advanced Webforms export.')
		case 'import_too_new':
			return t('webforms', 'This file was made by a newer version of Advanced Webforms. Update the app to import it.')
		case 'not_published_yet':
			return t('webforms', 'This form has not been published yet.')
		case 'too_large':
			return t('webforms', 'This form is too large.')
		default:
			return t('webforms', 'This is not valid ({code}).', { code })
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

/**
 * The errors worth showing while a form is still being built. A block that was just added and has
 * nothing in it yet (an image with no picture chosen) is not a mistake until publishing is tried.
 */
export function withoutUnstarted(errors: readonly DefinitionError[], definition: DraftDefinition | null): DefinitionError[] {
	return errors.filter((error) => {
		if (error.code !== 'field_image_invalid') {
			return true
		}
		const id = errorFieldId(error)
		const src = id ? definition?.fields[id]?.src : undefined
		return typeof src === 'string' && src.trim() !== ''
	})
}
