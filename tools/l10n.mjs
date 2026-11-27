/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Translation tooling without extra dependencies.
//
//   node tools/l10n.mjs extract   collect every t('webforms', '...') and n('webforms', '...', '...')
//                                 in src/, and every ->t('...') in lib/, into
//                                 translationfiles/templates/webforms.pot
//   node tools/l10n.mjs compile   turn translationfiles/<lang>/webforms.po into
//                                 l10n/<lang>.js and l10n/<lang>.json, the files Nextcloud loads
//
// English is the source language and needs no file. Translators work on the .pot / .po files
// (by hand, in Poedit, or in Transifex), and the compiled files are committed.

import { mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { join, relative } from 'node:path'

const APP_ID = 'webforms'
const DEFAULT_PLURAL = 'nplurals=2; plural=(n != 1);'
// Written in pieces so licence scanners read the real header above, not these strings.
const POT_HEADER = [
	'# SPDX' + '-FileCopyrightText: 2026 ArcaneX',
	'# SPDX' + '-License-Identifier: AGPL-3.0-or-later',
]

/** Source files that contain translatable strings. */
function sourceFiles(root) {
	const found = []
	const walk = (dir) => {
		for (const name of readdirSync(dir).sort()) {
			const path = join(dir, name)
			if (statSync(path).isDirectory()) {
				walk(path)
			} else if (/\.(vue|ts|php)$/.test(name) && !/\.test\.ts$/.test(name)) {
				found.push(path)
			}
		}
	}
	walk(join(root, 'src'))
	walk(join(root, 'lib'))
	return found
}

/** A single-quoted literal, the only form this code base uses. */
const LITERAL = String.raw`'((?:[^'\\\n]|\\.)*)'`
/** `t('webforms', 'text')` in the front end. */
const CALL = new RegExp(String.raw`\bt\(\s*'webforms'\s*,\s*` + LITERAL, 'g')
/** `n('webforms', 'one', 'many', count)` in the front end. */
const PLURAL_CALL = new RegExp(String.raw`\bn\(\s*'webforms'\s*,\s*` + LITERAL + String.raw`\s*,\s*` + LITERAL, 'g')
/** `$this->l10n->t('text')` on the server. */
const PHP_CALL = new RegExp(String.raw`->l(?:10n)?->t\(\s*` + LITERAL, 'g')

function unescapeJs(text) {
	return text.replace(/\\(.)/g, (_, char) => (char === 'n' ? '\n' : char === 't' ? '\t' : char))
}

/** A PHP single-quoted literal only escapes the quote and the backslash. */
function unescapePhp(text) {
	return text.replace(/\\(['\\])/g, '$1')
}

/** The key Nextcloud looks a plural message up by. */
function pluralKey(one, many) {
	return '_' + one + '_::_' + many + '_'
}

function splitPluralKey(key) {
	const match = /^_([\s\S]*)_::_([\s\S]*)_$/.exec(key)
	return match ? [match[1], match[2]] : null
}

/** @returns {Map<string, string[]>} message (or plural key) to the places it is used */
export function extractMessages(files, root) {
	const messages = new Map()
	for (const file of files) {
		const source = readFileSync(file, 'utf8')
		const add = (text, index) => {
			const line = source.slice(0, index).split('\n').length
			const places = messages.get(text) ?? []
			places.push(relative(root, file) + ':' + line)
			messages.set(text, places)
		}
		const php = file.endsWith('.php')
		const found = []
		for (const match of source.matchAll(php ? PHP_CALL : CALL)) {
			found.push([(php ? unescapePhp : unescapeJs)(match[1]), match.index])
		}
		if (!php) {
			for (const match of source.matchAll(PLURAL_CALL)) {
				found.push([pluralKey(unescapeJs(match[1]), unescapeJs(match[2])), match.index])
			}
		}
		for (const [text, index] of found.sort((a, b) => a[1] - b[1])) {
			add(text, index)
		}
	}
	return messages
}

function quotePo(text) {
	return '"' + text.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t') + '"'
}

export function toPot(messages) {
	const lines = [
		...POT_HEADER,
		'msgid ""',
		'msgstr ""',
		'"Project-Id-Version: Advanced Webforms\\n"',
		'"Content-Type: text/plain; charset=UTF-8\\n"',
		'"Content-Transfer-Encoding: 8bit\\n"',
		'"Plural-Forms: ' + DEFAULT_PLURAL + '\\n"',
		'',
	]
	for (const text of [...messages.keys()].sort((a, b) => a.localeCompare(b, 'en'))) {
		for (const place of messages.get(text).slice(0, 3)) {
			lines.push('#: ' + place)
		}
		const plural = splitPluralKey(text)
		if (plural) {
			lines.push('msgid ' + quotePo(plural[0]), 'msgid_plural ' + quotePo(plural[1]), 'msgstr[0] ""', 'msgstr[1] ""', '')
		} else {
			lines.push('msgid ' + quotePo(text), 'msgstr ""', '')
		}
	}
	return lines.join('\n')
}

function unquotePo(text) {
	return text.slice(1, -1).replace(/\\(.)/g, (_, char) => (char === 'n' ? '\n' : char === 't' ? '\t' : char))
}

/** @returns {{ translations: Record<string, string | string[]>, pluralForm: string }} untranslated entries are left out */
export function parsePo(source) {
	const translations = {}
	let pluralForm = DEFAULT_PLURAL
	let entry = { id: '', plural: null, str: [''] }
	/** Which part of the entry a continuation line belongs to. */
	let field = null
	const flush = () => {
		const { id, plural, str } = entry
		if (id === '') {
			const header = /Plural-Forms:\s*([^\n]+)/.exec(str[0])
			if (header) {
				pluralForm = header[1].trim()
			}
		} else if (str.every((form) => form !== '')) {
			if (plural === null) {
				translations[id] = str[0]
			} else {
				translations[pluralKey(id, plural)] = str
			}
		}
		entry = { id: '', plural: null, str: [''] }
	}
	for (const raw of source.split('\n')) {
		const line = raw.trim()
		const msgstr = /^msgstr(?:\[(\d+)\])? (".*")$/.exec(line)
		if (line.startsWith('msgid ')) {
			if (typeof field === 'number') {
				flush()
			}
			field = 'id'
			entry.id = unquotePo(line.slice(6))
		} else if (line.startsWith('msgid_plural ')) {
			field = 'plural'
			entry.plural = unquotePo(line.slice(13))
		} else if (msgstr) {
			field = Number(msgstr[1] ?? 0)
			entry.str[field] = unquotePo(msgstr[2])
		} else if (line.startsWith('"') && field !== null) {
			if (typeof field === 'number') {
				entry.str[field] += unquotePo(line)
			} else {
				entry[field] += unquotePo(line)
			}
		}
	}
	if (typeof field === 'number') {
		flush()
	}
	return { translations, pluralForm }
}

export function toJs({ translations, pluralForm }) {
	const body = Object.entries(translations)
		.map(([key, value]) => '\t' + JSON.stringify(key) + ' : ' + JSON.stringify(value))
		.join(',\n')
	return 'OC.L10N.register(\n\t"' + APP_ID + '",\n\t{\n' + body.replace(/^\t/gm, '\t\t') + '\n\t},\n\t' + JSON.stringify(pluralForm) + ');\n'
}

export function toJson({ translations, pluralForm }) {
	return JSON.stringify({ translations, pluralForm }, null, '\t') + '\n'
}

function main(command) {
	const root = process.cwd()
	if (command === 'extract') {
		const messages = extractMessages(sourceFiles(root), root)
		writeFileSync(join(root, 'translationfiles/templates', APP_ID + '.pot'), toPot(messages))
		console.log(messages.size + ' strings written to translationfiles/templates/' + APP_ID + '.pot')
	} else if (command === 'compile') {
		const base = join(root, 'translationfiles')
		let count = 0
		for (const lang of readdirSync(base)) {
			if (lang === 'templates' || !statSync(join(base, lang)).isDirectory()) {
				continue
			}
			const parsed = parsePo(readFileSync(join(base, lang, APP_ID + '.po'), 'utf8'))
			mkdirSync(join(root, 'l10n'), { recursive: true })
			writeFileSync(join(root, 'l10n', lang + '.js'), toJs(parsed))
			writeFileSync(join(root, 'l10n', lang + '.json'), toJson(parsed))
			count++
		}
		console.log(count + ' language(s) compiled into l10n/')
	} else {
		console.error('usage: node tools/l10n.mjs extract|compile')
		process.exit(1)
	}
}

if (import.meta.url === 'file://' + process.argv[1]) {
	main(process.argv[2])
}
