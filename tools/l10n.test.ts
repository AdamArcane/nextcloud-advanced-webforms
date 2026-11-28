/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mkdtempSync, mkdirSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
// @ts-expect-error plain JavaScript tooling
import { extractMessages, parsePo, sourceFiles, toJs, toJson, toPot } from './l10n.mjs'

describe('l10n tooling', () => {
	it('extracts messages with escapes and placeholders, once each', () => {
		const root = mkdtempSync(join(tmpdir(), 'l10n-'))
		mkdirSync(join(root, 'src'))
		const file = join(root, 'src', 'a.vue')
		writeFileSync(file, "t('webforms', 'Hello {name}')\nt('webforms', 'It\\'s here')\nt('webforms', 'Hello {name}')\n")
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

	it('reads PHP translation calls in lib/ and templates/', () => {
		const root = mkdtempSync(join(tmpdir(), 'l10n-'))
		for (const dir of ['src', 'lib/Deep', 'templates']) {
			mkdirSync(join(root, dir), { recursive: true })
		}
		writeFileSync(join(root, 'lib/Deep/A.php'), [
			"$this->l10n->t('Closed');",
			"$this->l->t(",
			"	'It\\'s %s', [$title]);",
			"$l10n->n('%n file', '%n files', $count);",
			"$this->l10n->t('A \\n stays');",
			"$other->t('Not a translation');",
		].join('\n'))
		writeFileSync(join(root, 'templates/main.php'), "<?php p($l->t('Hello')); ?>\n")
		const messages = extractMessages(sourceFiles(root), root)
		expect([...messages.keys()]).toEqual(['Closed', "It's %s", '_%n file_::_%n files_', 'A \\n stays', 'Hello'])
		expect(messages.get("It's %s")).toEqual(['lib/Deep/A.php:2'])
		expect(messages.get('Hello')).toEqual(['templates/main.php:1'])
	})

	it('carries a plural from the source to the compiled files', () => {
		const root = mkdtempSync(join(tmpdir(), 'l10n-'))
		mkdirSync(join(root, 'src'))
		writeFileSync(join(root, 'src', 'a.ts'), "n('webforms', '%n response', '%n responses', count)\n")
		const pot = toPot(extractMessages(sourceFiles(root), root))
		expect(pot).toContain('msgid "%n response"\nmsgid_plural "%n responses"\nmsgstr[0] ""\nmsgstr[1] ""\n')
		expect(parsePo(pot).translations).toEqual({})

		const translated = pot.replace('msgstr[0] ""', 'msgstr[0] "%n réponse"')
		expect(parsePo(translated).translations).toEqual({})
		const parsed = parsePo(translated.replace('msgstr[1] ""', 'msgstr[1] ""\n"%n réponses"'))
		expect(parsed.translations).toEqual({ '_%n response_::_%n responses_': ['%n réponse', '%n réponses'] })
		expect(toJs(parsed)).toContain('"_%n response_::_%n responses_" : ["%n réponse","%n réponses"]')
	})
})
