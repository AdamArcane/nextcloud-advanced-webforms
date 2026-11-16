/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DraftDefinition } from '../types.ts'

import { describe, expect, it } from 'vitest'
import { withoutUnstarted } from './errors.ts'

const definition = {
	fields: {
		empty_pic: { type: 'image', src: '' },
		bad_pic: { type: 'image', src: 'notes.txt' },
	},
} as unknown as DraftDefinition

describe('withoutUnstarted', () => {
	it('drops the picture error for an image block with nothing chosen yet', () => {
		const errors = [
			{ code: 'field_image_invalid', path: 'fields.empty_pic.src' },
			{ code: 'field_image_invalid', path: 'fields.bad_pic.src' },
			{ code: 'title_invalid', path: 'title' },
		]
		expect(withoutUnstarted(errors, definition)).toEqual(errors.slice(1))
	})
})
