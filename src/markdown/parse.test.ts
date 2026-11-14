/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Block, Inline } from './parse.ts'

import { describe, expect, it } from 'vitest'
import { parseMarkdown, safeHref } from './parse.ts'

const text = (value: string): Inline => ({ type: 'text', text: value })

describe('safeHref', () => {
	it('allows http, https, mailto and tel', () => {
		expect(safeHref('https://example.com/a?b=1')).toBe('https://example.com/a?b=1')
		expect(safeHref('http://example.com')).toBe('http://example.com/')
		expect(safeHref('mailto:a@example.com')).toBe('mailto:a@example.com')
		expect(safeHref('tel:+18605550100')).toBe('tel:+18605550100')
	})

	it('rejects other schemes and malformed values', () => {
		for (const bad of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', 'ftp://x.test', '//x.test', '/relative', '', 'https://', 'https://a b.test', 'java\tscript:x', ' \u0001https://x.test']) {
			expect(safeHref(bad), bad).toBeNull()
		}
	})
})

describe('parseMarkdown', () => {
	it('returns nothing for empty input', () => {
		expect(parseMarkdown('')).toEqual([])
		expect(parseMarkdown('  \n\n ')).toEqual([])
	})

	it('splits paragraphs and keeps single newlines as line breaks', () => {
		expect(parseMarkdown('one\ntwo\n\nthree')).toEqual<Block[]>([
			{ type: 'p', children: [text('one'), { type: 'br' }, text('two')] },
			{ type: 'p', children: [text('three')] },
		])
	})

	it('parses bold and italic, including nesting', () => {
		expect(parseMarkdown('a **b *c* d** _e_')).toEqual<Block[]>([{
			type: 'p',
			children: [
				text('a '),
				{ type: 'strong', children: [text('b '), { type: 'em', children: [text('c')] }, text(' d')] },
				text(' '),
				{ type: 'em', children: [text('e')] },
			],
		}])
	})

	it('leaves snake_case and lone markers literal', () => {
		expect(parseMarkdown('use my_var_name and 2 * 3')).toEqual<Block[]>([
			{ type: 'p', children: [text('use my_var_name and 2 * 3')] },
		])
	})

	it('supports escapes', () => {
		expect(parseMarkdown('\\*not italic\\*')).toEqual<Block[]>([{ type: 'p', children: [text('*not italic*')] }])
	})

	it('parses lists', () => {
		expect(parseMarkdown('- one\n- two **x**\n  more')).toEqual<Block[]>([{
			type: 'ul',
			items: [[text('one')], [text('two '), { type: 'strong', children: [text('x')] }, text(' more')]],
		}])
		expect(parseMarkdown('1. a\n2. b')).toEqual<Block[]>([{ type: 'ol', items: [[text('a')], [text('b')]] }])
	})

	it('creates links only for safe targets', () => {
		expect(parseMarkdown('[site](https://example.com)')).toEqual<Block[]>([
			{ type: 'p', children: [{ type: 'link', href: 'https://example.com/', children: [text('site')] }] },
		])
		// An unsafe target keeps only the label, never the URL.
		expect(parseMarkdown('[click](javascript:alert(1))')).toEqual<Block[]>([
			{ type: 'p', children: [text('click'), text(')')] },
		])
	})

	it('treats raw HTML as literal text', () => {
		const blocks = parseMarkdown('<script>alert(1)</script> <b>x</b>')
		expect(blocks).toEqual<Block[]>([{ type: 'p', children: [text('<script>alert(1)</script> <b>x</b>')] }])
	})

	it('does not support images or headings', () => {
		expect(parseMarkdown('# Title')).toEqual<Block[]>([{ type: 'p', children: [text('# Title')] }])
		const image = parseMarkdown('![alt](https://example.com/x.png)')
		expect(JSON.stringify(image)).not.toContain('"img"')
	})

	it('caps the input length', () => {
		const blocks = parseMarkdown('a'.repeat(5000))
		const joined = (blocks[0] as { children: { text: string }[] }).children[0].text
		expect(joined.length).toBe(2000)
	})

	it('survives pathological nesting without hanging', () => {
		const started = Date.now()
		parseMarkdown('*'.repeat(1000) + '[' .repeat(500) + '_'.repeat(500))
		expect(Date.now() - started).toBeLessThan(1000)
	})
})
