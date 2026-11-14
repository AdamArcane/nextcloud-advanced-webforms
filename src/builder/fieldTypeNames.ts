/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FieldGroup } from './definition.ts'

import { t } from '@nextcloud/l10n'

/** The name people read for a field type. One place, so the menu, the canvas and the panel agree. */
export function fieldTypeName(type: string): string {
	switch (type) {
		case 'text': return t('advancedforms', 'Short text')
		case 'textarea': return t('advancedforms', 'Long text')
		case 'email': return t('advancedforms', 'Email')
		case 'phone': return t('advancedforms', 'Phone')
		case 'url': return t('advancedforms', 'Website')
		case 'number': return t('advancedforms', 'Number')
		case 'select': return t('advancedforms', 'Dropdown')
		case 'radio': return t('advancedforms', 'Single choice')
		case 'checkboxes': return t('advancedforms', 'Multiple choice')
		case 'checkbox': return t('advancedforms', 'Checkbox')
		case 'date': return t('advancedforms', 'Date')
		case 'time': return t('advancedforms', 'Time')
		case 'file': return t('advancedforms', 'File upload')
		case 'heading': return t('advancedforms', 'Heading')
		case 'paragraph': return t('advancedforms', 'Paragraph')
		case 'divider': return t('advancedforms', 'Divider')
		default: return type
	}
}

/** A short line saying what a field is for, shown under its name in the add menu. */
export function fieldTypeHint(type: string): string {
	switch (type) {
		case 'text': return t('advancedforms', 'A name or a short answer')
		case 'textarea': return t('advancedforms', 'A message or a longer answer')
		case 'email': return t('advancedforms', 'Checked to be an email address')
		case 'phone': return t('advancedforms', 'Checked to be a phone number')
		case 'url': return t('advancedforms', 'A web address, checked to be one')
		case 'number': return t('advancedforms', 'With optional minimum and maximum')
		case 'select': return t('advancedforms', 'Pick one from a list')
		case 'radio': return t('advancedforms', 'Pick one, all options visible')
		case 'checkboxes': return t('advancedforms', 'Pick any number of options')
		case 'checkbox': return t('advancedforms', 'A single yes or no')
		case 'date': return t('advancedforms', 'Pick a day')
		case 'time': return t('advancedforms', 'Pick a time')
		case 'file': return t('advancedforms', 'Attach files to the response')
		case 'heading': return t('advancedforms', 'A title for a section')
		case 'paragraph': return t('advancedforms', 'Some explanatory text')
		case 'divider': return t('advancedforms', 'A line between sections')
		default: return ''
	}
}

export function fieldGroupName(group: FieldGroup): string {
	switch (group) {
		case 'text': return t('advancedforms', 'Text')
		case 'choice': return t('advancedforms', 'Choices')
		case 'datetime': return t('advancedforms', 'Date and time')
		case 'advanced': return t('advancedforms', 'Advanced')
		case 'content': return t('advancedforms', 'Content')
	}
}
