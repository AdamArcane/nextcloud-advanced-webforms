// @vitest-environment happy-dom
/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

const get = vi.hoisted(() => vi.fn())
const post = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({ default: { get, post }, isAxiosError: () => false }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path: string) => '/index.php' + path }))

import { fetchPublicForm, submitPublicForm, uploadPublicFile } from './api/public.ts'
import { isEmbedded, setEmbedded } from './embedded.ts'

afterEach(() => {
	setEmbedded(false)
	get.mockReset()
	post.mockReset()
})

const body = { versionId: 1, values: {}, fillToken: 't', hp: '' }

describe('the embedded form', () => {
	it('uses the ordinary routes on the form\'s own page', async () => {
		get.mockResolvedValue({ data: {} })
		post.mockResolvedValue({ data: {} })
		await fetchPublicForm('contact')
		await submitPublicForm('contact', body)
		await uploadPublicFile('contact', { versionId: 1, fillToken: 't' }, 'f_file', new File(['x'], 'x.txt'), () => {})
		expect(get.mock.calls[0][0]).toBe('/index.php/apps/webforms/api/v1/public/contact')
		expect(post.mock.calls[0][0]).toBe('/index.php/apps/webforms/api/v1/public/contact/submit')
		expect(post.mock.calls[1][0]).toBe('/index.php/apps/webforms/api/v1/public/contact/upload')
	})

	it('uses the embed routes, which need no session, inside a frame', async () => {
		setEmbedded(true)
		expect(isEmbedded()).toBe(true)
		get.mockResolvedValue({ data: {} })
		post.mockResolvedValue({ data: {} })
		await fetchPublicForm('contact')
		await submitPublicForm('contact', body)
		await uploadPublicFile('contact', { versionId: 1, fillToken: 't' }, 'f_file', new File(['x'], 'x.txt'), () => {})
		expect(get.mock.calls[0][0]).toBe('/index.php/apps/webforms/api/v1/public/contact/embed')
		expect(post.mock.calls[0][0]).toBe('/index.php/apps/webforms/api/v1/public/contact/embed/submit')
		expect(post.mock.calls[1][0]).toBe('/index.php/apps/webforms/api/v1/public/contact/embed/upload')
	})
})
