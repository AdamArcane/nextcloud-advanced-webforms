/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { autoHeightSnippet, iframeSnippet, parseOrigins, slugLooksValid, tidySlug } from './embed.ts'

describe('embed code', () => {
	it('is a plain frame with no script', () => {
		const html = iframeSnippet('https://cloud.example.com/apps/webforms/f/contact/embed', 'Contact us')
		expect(html).toBe('<iframe src="https://cloud.example.com/apps/webforms/f/contact/embed" title="Contact us" width="100%" height="600" style="border:0;max-width:100%" loading="lazy"></iframe>')
		expect(html).not.toContain('<script')
	})

	it('cannot be broken out of by a form title', () => {
		const html = iframeSnippet('https://cloud.example.com/f/x/embed', '"><script>alert(1)</script>')
		expect(html).not.toContain('<script>')
		expect(html).toContain('title="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"')
	})

	it('adds a script that fits the frame to the form', () => {
		const html = autoHeightSnippet('https://cloud.example.com/f/x/embed', 'T', 'webform-7')
		expect(html).toContain('<iframe id="webform-7"')
		expect(html).toContain('document.getElementById("webform-7")')
		expect(html).toContain('event.source === frame.contentWindow')
		expect(html).toContain('"webforms:height"')
		expect(html.trim().endsWith('</script>')).toBe(true)
	})

	it('keeps only safe characters in the frame id', () => {
		expect(autoHeightSnippet('https://x.example/f/x/embed', 'T', 'a"b<c')).toContain('<iframe id="abc"')
	})
})

describe('allowed websites', () => {
	it('reads one per line, or separated by commas and spaces', () => {
		expect(parseOrigins('https://a.example\nhttps://b.example/ , https://c.example\n\n')).toEqual(['https://a.example', 'https://b.example', 'https://c.example'])
		expect(parseOrigins('  ')).toEqual([])
	})
})

describe('custom address', () => {
	it('follows the same rule as the server', () => {
		expect(slugLooksValid('contact')).toBe(true)
		expect(slugLooksValid('summer-camp-2027')).toBe(true)
		expect(slugLooksValid('ab')).toBe(false)
		expect(slugLooksValid('Contact')).toBe(false)
		expect(slugLooksValid('-contact')).toBe(false)
		expect(slugLooksValid('contact-')).toBe(false)
		expect(slugLooksValid('contact--us')).toBe(false)
		expect(slugLooksValid('a'.repeat(65))).toBe(false)
	})

	it('tidies what a person types', () => {
		expect(tidySlug('  Summer Camp 2027! ')).toBe('summer-camp-2027')
		expect(tidySlug('Kontaktformulär')).toBe('kontaktformular')
		expect(tidySlug('a  --  b')).toBe('a-b')
		expect(tidySlug('contact')).toBe('contact')
	})
})
