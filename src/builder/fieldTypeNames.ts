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
		case 'datetime': return t('webforms', 'Date and time')
		case 'rating': return t('webforms', 'Rating')
		case 'scale': return t('webforms', 'Scale')
		case 'slider': return t('webforms', 'Slider')
		case 'matrix': return t('webforms', 'Matrix')
		case 'color': return t('webforms', 'Colour')
		case 'daterange': return t('webforms', 'Date range')
		case 'name': return t('webforms', 'Name')
		case 'address': return t('webforms', 'Address')
		case 'signature': return t('webforms', 'Signature')
		case 'consent': return t('webforms', 'Consent')
		case 'file': return t('webforms', 'File upload')
		case 'hidden': return t('webforms', 'Hidden value')
		case 'heading': return t('webforms', 'Heading')
		case 'paragraph': return t('webforms', 'Paragraph')
		case 'divider': return t('webforms', 'Divider')
		case 'image': return t('webforms', 'Image')
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
		case 'datetime': return t('webforms', 'A day and a time in one answer')
		case 'rating': return t('webforms', 'Stars, from 1 to 5 or more')
		case 'scale': return t('webforms', 'A number on a line, such as 0 to 10')
		case 'slider': return t('webforms', 'Drag a handle to a number')
		case 'matrix': return t('webforms', 'A grid: one choice per question')
		case 'color': return t('webforms', 'Pick a colour')
		case 'daterange': return t('webforms', 'A first and a last day')
		case 'name': return t('webforms', 'First, last and other parts of a name')
		case 'address': return t('webforms', 'Street, city, postal code and country')
		case 'signature': return t('webforms', 'Draw a signature with a finger or a mouse')
		case 'consent': return t('webforms', 'Agree to a text, with links')
		case 'file': return t('webforms', 'Attach files to the response')
		case 'hidden': return t('webforms', 'Not shown, filled from the link or a default')
		case 'heading': return t('webforms', 'A title for a section')
		case 'paragraph': return t('webforms', 'Some explanatory text')
		case 'divider': return t('webforms', 'A line between sections')
		case 'image': return t('webforms', 'A picture from your Files or a web address')
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
