/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Limited markdown for `paragraph` fields (docs/SCHEMA.md section 4.5).
// It produces a small AST that Vue renders as elements, never as an HTML
// string, so there is nothing to sanitize and no way to inject markup.
//
// Supported: paragraphs, line breaks, **bold**, *italic*, _italic_, ordered
// and unordered lists, [links](https://...). Everything else, including raw
// HTML, stays literal text.

export type Inline =
	| { type: 'text', text: string }
	| { type: 'br' }
	| { type: 'strong' | 'em', children: Inline[] }
	| { type: 'link', href: string, children: Inline[] }

export type Block =
	| { type: 'p', children: Inline[] }
	| { type: 'ul' | 'ol', items: Inline[][] }

export const MAX_LENGTH = 2000
const MAX_DEPTH = 5

/** Returns the safe form of a link target, or null when the scheme is not allowed. */
export function safeHref(raw: string): string | null {
	const url = raw.trim()
	// eslint-disable-next-line no-control-regex
	if (url === '' || /[\s\u0000-\u001f\u007f]/.test(url)) {
		return null
	}
	if (/^(mailto|tel):\S+$/i.test(url)) {
		return url
	}
	if (/^https?:\/\//i.test(url)) {
		try {
			const parsed = new URL(url)
			return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : null
		} catch {
			return null
		}
	}
	return null
}

export function parseMarkdown(source: string): Block[] {
	const text = source.slice(0, MAX_LENGTH).replace(/\r\n?/g, '\n').trim()
	if (text === '') {
		return []
	}
	return text.split(/\n{2,}/).map(parseBlock)
}

const UNORDERED = /^[-*+] +(.*)$/
const ORDERED = /^\d{1,9}[.)] +(.*)$/

function parseBlock(chunk: string): Block {
	const lines = chunk.split('\n')
	const first = lines[0]
	const kind = UNORDERED.test(first) ? 'ul' : (ORDERED.test(first) ? 'ol' : null)

	if (kind === null) {
		return { type: 'p', children: parseLines(lines) }
	}

	const marker = kind === 'ul' ? UNORDERED : ORDERED
	const items: string[] = []
	for (const line of lines) {
		const match = marker.exec(line)
		if (match) {
			items.push(match[1])
		} else {
			// Lazy continuation: an unmarked line extends the previous item.
			items[items.length - 1] += ' ' + line.trim()
		}
	}
	return { type: kind, items: items.map((item) => parseInline(item, 0)) }
}

function parseLines(lines: string[]): Inline[] {
	const result: Inline[] = []
	lines.forEach((line, index) => {
		if (index > 0) {
			result.push({ type: 'br' })
		}
		result.push(...parseInline(line, 0))
	})
	return result
}

const ESCAPABLE = '\\*_[]()'

function parseInline(s: string, depth: number): Inline[] {
	const out: Inline[] = []
	let buffer = ''
	const flush = () => {
		if (buffer !== '') {
			out.push({ type: 'text', text: buffer })
			buffer = ''
		}
	}

	let i = 0
	while (i < s.length) {
		const ch = s[i]

		if (ch === '\\' && i + 1 < s.length && ESCAPABLE.includes(s[i + 1])) {
			buffer += s[i + 1]
			i += 2
			continue
		}

		if (depth < MAX_DEPTH) {
			// **bold**
			if (ch === '*' && s[i + 1] === '*') {
				const end = findClose(s, i + 2, '**')
				if (end > i + 2) {
					flush()
					out.push({ type: 'strong', children: parseInline(s.slice(i + 2, end), depth + 1) })
					i = end + 2
					continue
				}
			}

			// *italic* and _italic_ (underscore only at word edges, so snake_case stays literal)
			if ((ch === '*' && s[i + 1] !== '*') || (ch === '_' && !isWordChar(s[i - 1]))) {
				const end = findClose(s, i + 1, ch)
				const closes = end > i + 1 && (ch === '*' || !isWordChar(s[end + 1]))
				if (closes && s[end - 1] !== ' ' && s[i + 1] !== ' ') {
					flush()
					out.push({ type: 'em', children: parseInline(s.slice(i + 1, end), depth + 1) })
					i = end + 1
					continue
				}
			}

			// [label](url)
			if (ch === '[') {
				const labelEnd = findClose(s, i + 1, ']')
				if (labelEnd > i && s[labelEnd + 1] === '(') {
					const urlEnd = s.indexOf(')', labelEnd + 2)
					if (urlEnd > labelEnd) {
						const label = parseInline(s.slice(i + 1, labelEnd), depth + 1)
						const href = safeHref(s.slice(labelEnd + 2, urlEnd))
						flush()
						// An unsafe or malformed target keeps only the label, as plain text.
						if (href === null) {
							out.push(...label)
						} else {
							out.push({ type: 'link', href, children: label })
						}
						i = urlEnd + 1
						continue
					}
				}
			}
		}

		buffer += ch
		i++
	}
	flush()
	return out
}

function isWordChar(c: string | undefined): boolean {
	return c !== undefined && /[\p{L}\p{N}]/u.test(c)
}

/** Index of the next unescaped `token` at or after `from`, or -1. */
function findClose(s: string, from: number, token: string): number {
	for (let i = from; i < s.length; i++) {
		if (s[i] === '\\') {
			i++
			continue
		}
		if (s.startsWith(token, i)) {
			return i
		}
	}
	return -1
}
