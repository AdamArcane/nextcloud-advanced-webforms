/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { solveChallenge } from './pow.ts'
import { sha256Hex } from './sha256.ts'

describe('sha256Hex', () => {
	it('matches known digests', () => {
		expect(sha256Hex('')).toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')
		expect(sha256Hex('abc')).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')
	})

	it('handles input longer than one block', () => {
		const text = 'a'.repeat(1000)
		expect(sha256Hex(text)).toBe('41edece42d63e8d9bf515a9ba6932e1c20cbc9f5a5d134645adb5db1b9737ea3')
	})

	it('matches the browser implementation for the salt format the server uses', async () => {
		const input = 'ab12cd34ef56ab12cd34ef56?expires=1900000000' + 4242
		const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(input))
		const expected = Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('')
		expect(sha256Hex(input)).toBe(expected)
	})
})

describe('solveChallenge', () => {
	const salt = 'ab12cd34ef56ab12cd34ef56?expires=1900000000'

	it('finds the number and returns the payload the server reads', async () => {
		const challenge = { algorithm: 'SHA-256', challenge: sha256Hex(salt + 1234), salt, signature: 'sig', maxnumber: 5000 }
		const payload = JSON.parse(atob(await solveChallenge(challenge)))
		expect(payload).toEqual({ algorithm: 'SHA-256', challenge: challenge.challenge, number: 1234, salt, signature: 'sig' })
	})

	it('rejects a challenge that has no answer in range', async () => {
		const challenge = { algorithm: 'SHA-256', challenge: sha256Hex(salt + 9999), salt, signature: 'sig', maxnumber: 50 }
		await expect(solveChallenge(challenge)).rejects.toThrow()
	})

	it('stops when cancelled', async () => {
		const controller = new AbortController()
		controller.abort()
		const challenge = { algorithm: 'SHA-256', challenge: sha256Hex(salt + 3000), salt, signature: 'sig', maxnumber: 5000 }
		await expect(solveChallenge(challenge, controller.signal)).rejects.toThrow('cancelled')
	})
})
