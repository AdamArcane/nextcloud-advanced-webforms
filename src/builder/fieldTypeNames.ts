/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FieldGroup } from './definition.ts'

import { t } from '@nextcloud/l10n'

/** The name people read for a field type. One place, so the menu, the canvas and the panel agree. */
export function fieldTypeName(type: string): string {
	switch (type) {
		case 'text': return t('webforms', 'Short text')
		case 'textarea': return t('webforms', 'Long text')
		case 'email': return t('webforms', 'Email')
		case 'phone': return t('webforms', 'Phone')
		case 'url': return t('webforms', 'Website')
		case 'number': return t('webforms', 'Number')
		case 'select': return t('webforms', 'Dropdown')
		case 'radio': return t('webforms', 'Single choice')
		case 'checkboxes': return t('webforms', 'Multiple choice')
		case 'checkbox': return t('webforms', 'Checkbox')
		case 'date': return t('webforms', 'Date')
		case 'time': return t('webforms', 'Time')
		case 'file': return t('webforms', 'File upload')
		case 'heading': return t('webforms', 'Heading')
		case 'paragraph': return t('webforms', 'Paragraph')
		case 'divider': return t('webforms', 'Divider')
		default: return type
	}
}

/** A short line saying what a field is for, shown under its name in the add menu. */
export function fieldTypeHint(type: string): string {
	switch (type) {
		case 'text': return t('webforms', 'A name or a short answer')
		case 'textarea': return t('webforms', 'A message or a longer answer')
		case 'email': return t('webforms', 'Checked to be an email address')
		case 'phone': return t('webforms', 'Checked to be a phone number')
		case 'url': return t('webforms', 'A web address, checked to be one')
		case 'number': return t('webforms', 'With optional minimum and maximum')
		case 'select': return t('webforms', 'Pick one from a list')
		case 'radio': return t('webforms', 'Pick one, all options visible')
		case 'checkboxes': return t('webforms', 'Pick any number of options')
		case 'checkbox': return t('webforms', 'A single yes or no')
		case 'date': return t('webforms', 'Pick a day')
		case 'time': return t('webforms', 'Pick a time')
		case 'file': return t('webforms', 'Attach files to the response')
		case 'heading': return t('webforms', 'A title for a section')
		case 'paragraph': return t('webforms', 'Some explanatory text')
		case 'divider': return t('webforms', 'A line between sections')
		default: return ''
	}
}

export function fieldGroupName(group: FieldGroup): string {
	switch (group) {
		case 'text': return t('webforms', 'Text')
		case 'choice': return t('webforms', 'Choices')
		case 'datetime': return t('webforms', 'Date and time')
		case 'advanced': return t('webforms', 'Advanced')
		case 'content': return t('webforms', 'Content')
	}
}
