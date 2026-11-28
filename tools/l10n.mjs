/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Translation tooling without extra dependencies.
//
//   node tools/l10n.mjs extract   collect every t('webforms', ...) and n('webforms', ...) in src/,
//                                 and every ->t(...) and ->n(...) in lib/ and templates/, into
//                                 translationfiles/templates/webforms.pot
//   node tools/l10n.mjs compile   turn translationfiles/<lang>/webforms.po into
//                                 l10n/<lang>.js and l10n/<lang>.json, the files Nextcloud loads
//
// English is the source language and needs no file. Translators work on the .pot / .po files
// (by hand, in Poedit, or in Transifex), and the compiled files are committed.

import { existsSync, mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { join, relative } from 'node:path'

const APP_ID = 'webforms'
const DEFAULT_PLURAL = 'nplurals=2; plural=(n != 1);'
// Written in pieces so licence scanners read the real header above, not these strings.
const POT_HEADER = [
	'# SPDX' + '-FileCopyrightText: 2026 ArcaneX',
	'# SPDX' + '-License-Identifier: AGPL-3.0-or-later',
]

/** Source files that contain translatable strings. */
export function sourceFiles(root) {
	const found = []
	const walk = (dir, wanted) => {
		for (const name of readdirSync(dir).sort()) {
			const path = join(dir, name)
			if (statSync(path).isDirectory()) {
				walk(path, wanted)
			} else if (wanted(name)) {
				found.push(path)
			}
		}
	}
	walk(join(root, 'src'), (name) => /\.(vue|ts)$/.test(name) && !/\.test\.ts$/.test(name))
	for (const dir of ['lib', 'templates']) {
		if (existsSync(join(root, dir))) {
			walk(join(root, dir), (name) => name.endsWith('.php'))
		}
	}
	return found
}

/** A single-quoted literal, the only form this code base uses. PHP lets one run over several lines. */
const JS_TEXT = String.raw`'((?:[^'\\\n]|\\.)*)'`
const PHP_TEXT = String.raw`'((?:[^'\\]|\\[\s\S])*)'`
/** `$l->t(`, `$this->l10n->n(` and the like. Other objects with a `t` or `n` method are left alone. */
const PHP_CALL = String.raw`(?:\$|->)(?:l|l10n)->`

const SYNTAX = {
	js: {
		singular: new RegExp(String.raw`\bt\(\s*'webforms'\s*,\s*` + JS_TEXT, 'g'),
		plural: new RegExp(String.raw`\bn\(\s*'webforms'\s*,\s*` + JS_TEXT + String.raw`\s*,\s*` + JS_TEXT, 'g'),
		unescape: (text) => text.replace(/\\(.)/g, (_, char) => (char === 'n' ? '\n' : char === 't' ? '\t' : char)),
	},
	php: {
		singular: new RegExp(PHP_CALL + String.raw`t\(\s*` + PHP_TEXT, 'g'),
		plural: new RegExp(PHP_CALL + String.raw`n\(\s*` + PHP_TEXT + String.raw`\s*,\s*` + PHP_TEXT, 'g'),
		// In a single-quoted PHP string only \' and \\ are escapes, everything else is literal.
		unescape: (text) => text.replace(/\\([\\'])/g, '$1'),
	},
}

/** The key Nextcloud looks a plural message up by, in both PHP and JavaScript. */
const pluralKey = (singular, plural) => '_' + singular + '_::_' + plural + '_'
const PLURAL_KEY = /^_([\s\S]*)_::_([\s\S]*)_$/

/** @returns {Map<string, string[]>} message (or plural key) to the places it is used */
export function extractMessages(files, root) {
	const messages = new Map()
	for (const file of files) {
		const source = readFileSync(file, 'utf8')
		const syntax = file.endsWith('.php') ? SYNTAX.php : SYNTAX.js
		const found = [
			...[...source.matchAll(syntax.singular)].map((match) => [match.index, syntax.unescape(match[1])]),
			...[...source.matchAll(syntax.plural)].map((match) => [match.index, pluralKey(syntax.unescape(match[1]), syntax.unescape(match[2]))]),
		].sort((a, b) => a[0] - b[0])
		for (const [index, key] of found) {
			const line = source.slice(0, index).split('\n').length
			const places = messages.get(key) ?? []
			places.push(relative(root, file) + ':' + line)
			messages.set(key, places)
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
	const singularOf = (key) => PLURAL_KEY.exec(key)?.[1] ?? key
	for (const key of [...messages.keys()].sort((a, b) => singularOf(a).localeCompare(singularOf(b), 'en'))) {
		for (const place of messages.get(key).slice(0, 3)) {
			lines.push('#: ' + place)
		}
		const plural = PLURAL_KEY.exec(key)
		if (plural) {
			lines.push('msgid ' + quotePo(plural[1]), 'msgid_plural ' + quotePo(plural[2]), 'msgstr[0] ""', 'msgstr[1] ""', '')
		} else {
			lines.push('msgid ' + quotePo(key), 'msgstr ""', '')
		}
	}
	return lines.join('\n')
}

function unquotePo(text) {
	return text.slice(1, -1).replace(/\\(.)/g, (_, char) => (char === 'n' ? '\n' : char === 't' ? '\t' : char))
}

/**
 * @returns {{ translations: Record<string, string | string[]>, pluralForm: string }} untranslated
 *   entries are left out. A plural entry is only taken when every one of its forms is translated.
 */
export function parsePo(source) {
	const translations = {}
	let pluralForm = DEFAULT_PLURAL
	let entry = { id: '', plural: null, str: [] }
	// Where a continuation line belongs.
	let append = null
	let sawStr = false
	const flush = () => {
		const { id, plural, str } = entry
		if (id === '') {
			const header = /Plural-Forms:\s*([^\n]+)/.exec(str[0] ?? '')
			if (header) {
				pluralForm = header[1].trim()
			}
		} else if (plural !== null) {
			if (str.length > 0 && str.every((form) => form !== '')) {
				translations[pluralKey(id, plural)] = str
			}
		} else if (str[0]) {
			translations[id] = str[0]
		}
		entry = { id: '', plural: null, str: [] }
		sawStr = false
	}
	for (const raw of source.split('\n')) {
		const line = raw.trim()
		const msgstr = /^msgstr(?:\[(\d+)\])?\s+(".*")$/.exec(line)
		if (line.startsWith('msgid ')) {
			if (sawStr) {
				flush()
			}
			entry.id = unquotePo(line.slice(6))
			append = (text) => { entry.id += text }
		} else if (line.startsWith('msgid_plural ')) {
			entry.plural = unquotePo(line.slice(13))
			append = (text) => { entry.plural += text }
		} else if (msgstr) {
			const index = Number(msgstr[1] ?? 0)
			sawStr = true
			entry.str[index] = unquotePo(msgstr[2])
			append = (text) => { entry.str[index] += text }
		} else if (line.startsWith('"') && append !== null) {
			append(unquotePo(line))
		}
	}
	if (sawStr) {
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
