/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { templateStrings, translateTemplateText } from './catalog.ts'

// Mirrors lib/Forms/TemplateText.php: the properties whose text a respondent reads.
const KEYS = new Set(['title', 'description', 'label', 'text', 'placeholder', 'submitLabel', 'closedMessage', 'minLabel', 'maxLabel', 'reviewTitle'])
const DIR = join(__dirname, '../../lib/Forms/Templates')

function words(node: unknown, key: string | null, out: Set<string>): void {
	if (Array.isArray(node)) {
		node.forEach((item) => words(item, key, out))
	} else if (node !== null && typeof node === 'object') {
		Object.entries(node).forEach(([k, v]) => words(v, k, out))
	} else if (typeof node === 'string' && key !== null && KEYS.has(key)) {
		out.add(node)
	}
}

const files = readdirSync(DIR).filter((name) => name.endsWith('.json'))

describe('starter template text', () => {
	it('finds the template files', () => {
		expect(files.length).toBeGreaterThanOrEqual(6)
	})

	for (const file of files) {
		it(`has a translatable string for every word of ${file}`, () => {
			const data = JSON.parse(readFileSync(join(DIR, file), 'utf8'))
			const needed = new Set<string>([data.name, data.description])
			words(data.definition, null, needed)
			const known = templateStrings()
			expect([...needed].filter((text) => !(text in known))).toEqual([])
		})
	}

	it('has a name for every category', () => {
		const known = templateStrings()
		for (const file of files) {
			const category: string = JSON.parse(readFileSync(join(DIR, file), 'utf8')).category
			expect(known[category.charAt(0).toUpperCase() + category.slice(1)]).toBeDefined()
		}
	})

	it('hands back text it does not know as it is', () => {
		expect(translateTemplateText('Something else')).toBe('Something else')
	})
})
