/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Translation tooling without extra dependencies.
//
//   node tools/l10n.mjs extract   collect every t('advancedforms', '...') into
//                                 translationfiles/templates/advancedforms.pot
//   node tools/l10n.mjs compile   turn translationfiles/<lang>/advancedforms.po into
//                                 l10n/<lang>.js and l10n/<lang>.json, the files Nextcloud loads
//
// English is the source language and needs no file. Translators work on the .pot / .po files
// (by hand, in Poedit, or in Transifex), and the compiled files are committed.

import { mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { join, relative } from 'node:path'

const APP_ID = 'advancedforms'
const DEFAULT_PLURAL = 'nplurals=2; plural=(n != 1);'

/** Source files that contain translatable strings. */
function sourceFiles(root) {
	const found = []
	const walk = (dir) => {
		for (const name of readdirSync(dir).sort()) {
			const path = join(dir, name)
			if (statSync(path).isDirectory()) {
				walk(path)
			} else if (/\.(vue|ts)$/.test(name) && !/\.test\.ts$/.test(name)) {
				found.push(path)
			}
		}
	}
	walk(join(root, 'src'))
	return found
}

/** `t('advancedforms', 'text')` with a single-quoted literal, the only form this code base uses. */
const CALL = /\bt\(\s*'advancedforms'\s*,\s*'((?:[^'\\\n]|\\.)*)'/g

function unescapeJs(text) {
	return text.replace(/\\(.)/g, (_, char) => (char === 'n' ? '\n' : char === 't' ? '\t' : char))
}

/** @returns {Map<string, string[]>} message to the places it is used */
export function extractMessages(files, root) {
	const messages = new Map()
	for (const file of files) {
		const source = readFileSync(file, 'utf8')
		for (const match of source.matchAll(CALL)) {
			const text = unescapeJs(match[1])
			const line = source.slice(0, match.index).split('\n').length
			const places = messages.get(text) ?? []
			places.push(relative(root, file) + ':' + line)
			messages.set(text, places)
		}
	}
	return messages
}

function quotePo(text) {
	return '"' + text.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t') + '"'
}

export function toPot(messages) {
	const lines = [
		'# SPDX-FileCopyrightText: 2026 ArcaneX',
		'# SPDX-License-Identifier: AGPL-3.0-or-later',
		'msgid ""',
		'msgstr ""',
		'"Project-Id-Version: Advanced Forms\\n"',
		'"Content-Type: text/plain; charset=UTF-8\\n"',
		'"Content-Transfer-Encoding: 8bit\\n"',
		'"Plural-Forms: ' + DEFAULT_PLURAL + '\\n"',
		'',
	]
	for (const text of [...messages.keys()].sort((a, b) => a.localeCompare(b, 'en'))) {
		for (const place of messages.get(text).slice(0, 3)) {
			lines.push('#: ' + place)
		}
		lines.push('msgid ' + quotePo(text), 'msgstr ""', '')
	}
	return lines.join('\n')
}

function unquotePo(text) {
	return text.slice(1, -1).replace(/\\(.)/g, (_, char) => (char === 'n' ? '\n' : char === 't' ? '\t' : char))
}

/** @returns {{ translations: Record<string, string>, pluralForm: string }} untranslated entries are left out */
export function parsePo(source) {
	const translations = {}
	let pluralForm = DEFAULT_PLURAL
	let field = null
	let id = ''
	let str = ''
	const flush = () => {
		if (id === '') {
			const header = /Plural-Forms:\s*([^\n]+)/.exec(str)
			if (header) {
				pluralForm = header[1].trim()
			}
		} else if (str !== '') {
			translations[id] = str
		}
		id = ''
		str = ''
	}
	for (const raw of source.split('\n')) {
		const line = raw.trim()
		if (line.startsWith('msgid ')) {
			if (field === 'str') {
				flush()
			}
			field = 'id'
			id = unquotePo(line.slice(6))
		} else if (line.startsWith('msgstr ')) {
			field = 'str'
			str = unquotePo(line.slice(7))
		} else if (line.startsWith('"') && field !== null) {
			if (field === 'id') {
				id += unquotePo(line)
			} else {
				str += unquotePo(line)
			}
		}
	}
	if (field === 'str') {
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
