/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mkdtempSync, mkdirSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
// @ts-expect-error plain JavaScript tooling
import { extractMessages, parsePo, toJs, toJson, toPot } from './l10n.mjs'

describe('l10n tooling', () => {
	it('extracts messages with escapes and placeholders, once each', () => {
		const root = mkdtempSync(join(tmpdir(), 'l10n-'))
		mkdirSync(join(root, 'src'))
		const file = join(root, 'src', 'a.vue')
		writeFileSync(file, "t('advancedforms', 'Hello {name}')\nt('advancedforms', 'It\\'s here')\nt('advancedforms', 'Hello {name}')\n")
		const messages = extractMessages([file], root)
		expect([...messages.keys()]).toEqual(['Hello {name}', "It's here"])
		expect(messages.get('Hello {name}')).toEqual(['src/a.vue:1', 'src/a.vue:3'])
	})

	it('writes a template that parses back with nothing translated', () => {
		const pot = toPot(new Map([['Say "hi"', ['src/a.ts:1']]]))
		expect(pot).toContain('msgid "Say \\"hi\\""')
		expect(parsePo(pot).translations).toEqual({})
	})

	it('compiles a translation to the files Nextcloud loads', () => {
		const parsed = parsePo('msgid ""\nmsgstr ""\n"Plural-Forms: nplurals=2; plural=(n > 1);\\n"\n\nmsgid "Send"\nmsgstr "Envoyer"\n\nmsgid "Long "\n"text"\nmsgstr "Texte "\n"long"\n\nmsgid "Skipped"\nmsgstr ""\n')
		expect(parsed.translations).toEqual({ Send: 'Envoyer', 'Long text': 'Texte long' })
		expect(parsed.pluralForm).toBe('nplurals=2; plural=(n > 1);')
		expect(toJs(parsed)).toContain('"Send" : "Envoyer"')
		expect(toJs(parsed)).toContain('OC.L10N.register(')
		expect(JSON.parse(toJson(parsed))).toEqual(parsed)
	})
})
