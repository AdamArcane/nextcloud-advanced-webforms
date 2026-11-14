/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { FormField } from '../../types.ts'

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'

const uploadPublicFile = vi.fn()
class FakeApiError extends Error {
	constructor(public status: number, public code: string, public errors: { field?: string, code: string }[] = []) {
		super(code)
	}
}
vi.mock('../../api/public.ts', () => ({ uploadPublicFile: (...args: unknown[]) => uploadPublicFile(...args), ApiError: FakeApiError }))

const { createUploadState } = await import('./uploadState.ts')

const context = { token: 't', versionId: 3, fillToken: 'f', signedIn: false, preview: false }
const file = (name: string, size = 100) => new File([new Uint8Array(size)], name)

function setup(field: FormField, preview = false) {
	const values = reactive<Record<string, unknown>>({ f_cv: [] })
	const state = createUploadState(() => ({ ...context, preview }), values)
	return { state, values, field }
}

// Braces matter: a function returned from beforeEach is run as a cleanup hook.
beforeEach(() => {
	uploadPublicFile.mockReset()
})

describe('upload state', () => {
	it('uploads a file and keeps its id as the answer', async () => {
		uploadPublicFile.mockResolvedValue({ id: 'id1', name: 'cv.pdf', size: 100 })
		const { state, values, field } = setup({ type: 'file', maxFiles: 3 })
		state.add('f_cv', field, [file('cv.pdf')])
		expect(state.busy.value).toBe(true)
		await vi.waitFor(() => expect(state.busy.value).toBe(false))
		expect(values.f_cv).toEqual(['id1'])
		expect(state.items('f_cv')[0].status).toBe('done')
		expect(uploadPublicFile).toHaveBeenCalledWith('t', expect.objectContaining({ versionId: 3, fillToken: 'f' }), 'f_cv', expect.any(File), expect.any(Function))
	})

	it('refuses files the browser can already tell are not allowed, without a request', () => {
		const { state, values, field } = setup({ type: 'file' })
		state.add('f_cv', field, [file('run.exe'), file('big.pdf', 11 * 1024 * 1024)])
		expect(uploadPublicFile).not.toHaveBeenCalled()
		expect(values.f_cv).toEqual([])
		// One file per field: only the first is looked at, and it is refused.
		expect(state.items('f_cv')).toHaveLength(1)
		expect(state.items('f_cv')[0].status).toBe('error')
	})

	it('shows the server reason when an upload is refused, and keeps the answer empty', async () => {
		uploadPublicFile.mockRejectedValue(new FakeApiError(422, 'validation', [{ field: 'f_cv', code: 'upload_type_not_allowed' }]))
		const { state, values, field } = setup({ type: 'file' })
		state.add('f_cv', field, [file('cv.pdf')])
		await vi.waitFor(() => expect(state.busy.value).toBe(false))
		expect(state.items('f_cv')[0].status).toBe('error')
		expect(state.items('f_cv')[0].error).toContain('not accepted')
		expect(values.f_cv).toEqual([])
	})

	it('replaces the file when the field takes one', async () => {
		uploadPublicFile.mockResolvedValueOnce({ id: 'a', name: 'a.pdf', size: 1 }).mockResolvedValueOnce({ id: 'b', name: 'b.pdf', size: 1 })
		const { state, values, field } = setup({ type: 'file' })
		state.add('f_cv', field, [file('a.pdf')])
		await vi.waitFor(() => expect(values.f_cv).toEqual(['a']))
		state.add('f_cv', field, [file('b.pdf')])
		await vi.waitFor(() => expect(values.f_cv).toEqual(['b']))
		expect(state.items('f_cv')).toHaveLength(1)
	})

	it('stops at the limit and says so', async () => {
		uploadPublicFile.mockImplementation(async (_token: string, _context: unknown, _field: string, f: File) => ({ id: f.name, name: f.name, size: 1 }))
		const { state, values, field } = setup({ type: 'file', maxFiles: 2 })
		state.add('f_cv', field, [file('a.pdf'), file('b.pdf'), file('c.pdf')])
		await vi.waitFor(() => expect(values.f_cv).toEqual(['a.pdf', 'b.pdf']))
		expect(state.notice('f_cv')).toContain('too many')
		expect(state.items('f_cv')).toHaveLength(2)
	})

	it('removes a file from the answer', async () => {
		uploadPublicFile.mockResolvedValue({ id: 'a', name: 'a.pdf', size: 1 })
		const { state, values, field } = setup({ type: 'file', maxFiles: 2 })
		state.add('f_cv', field, [file('a.pdf')])
		await vi.waitFor(() => expect(values.f_cv).toEqual(['a']))
		state.remove('f_cv', state.items('f_cv')[0])
		expect(values.f_cv).toEqual([])
		expect(state.items('f_cv')).toHaveLength(0)
	})

	it('does not send anything from the preview', () => {
		const { state, values, field } = setup({ type: 'file' }, true)
		state.add('f_cv', field, [file('cv.pdf')])
		expect(uploadPublicFile).not.toHaveBeenCalled()
		expect(values.f_cv).toHaveLength(1)
		expect(state.busy.value).toBe(false)
	})
})
