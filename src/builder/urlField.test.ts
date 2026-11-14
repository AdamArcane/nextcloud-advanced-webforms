/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { addField, emptyDefinition } from './definition.ts'
import { fieldTypeName } from './fieldTypeNames.ts'
import { fieldTypeIcon } from '../icons.ts'
import { isInputType, operatorsFor } from '../rules/fieldTypes.ts'

describe('website field', () => {
	it('can be added, named, and used in rules like text', () => {
		const { definition, fieldId } = addField(emptyDefinition('T'), 'url', { kind: 'row', index: 0 })
		expect(definition.fields[fieldId]).toMatchObject({ type: 'url', label: 'Website' })
		expect(fieldTypeName('url')).toBe('Website')
		expect(fieldTypeIcon('url')).not.toBe(fieldTypeIcon('text'))
		expect(isInputType('url')).toBe(true)
		expect(operatorsFor('url')).toEqual(operatorsFor('text'))
	})
})
