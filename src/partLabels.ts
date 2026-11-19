/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

/** The words for the parts of a name or an address, one place so the form and the builder agree. */
export function partLabel(part: string): string {
	switch (part) {
		case 'prefix': return t('webforms', 'Title')
		case 'first': return t('webforms', 'First name')
		case 'middle': return t('webforms', 'Middle name')
		case 'last': return t('webforms', 'Last name')
		case 'suffix': return t('webforms', 'Suffix')
		case 'street': return t('webforms', 'Street address')
		case 'street2': return t('webforms', 'Address line 2')
		case 'city': return t('webforms', 'City')
		case 'region': return t('webforms', 'State or region')
		case 'postal': return t('webforms', 'Postal code')
		case 'country': return t('webforms', 'Country')
		default: return part
	}
}

/** The browser's autofill hint for a part. */
export function partAutocomplete(part: string): string {
	switch (part) {
		case 'prefix': return 'honorific-prefix'
		case 'first': return 'given-name'
		case 'middle': return 'additional-name'
		case 'last': return 'family-name'
		case 'suffix': return 'honorific-suffix'
		case 'street': return 'address-line1'
		case 'street2': return 'address-line2'
		case 'city': return 'address-level2'
		case 'region': return 'address-level1'
		case 'postal': return 'postal-code'
		case 'country': return 'country-name'
		default: return 'off'
	}
}
