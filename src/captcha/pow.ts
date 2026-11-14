/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { sha256Hex } from './sha256.ts'

/** What the server hands out for the built-in check (ALTCHA protocol, SHA-256). */
export interface PowChallenge {
	algorithm: string
	challenge: string
	salt: string
	signature: string
	maxnumber: number
}

/** Numbers tried between two pauses, so the page stays responsive while it works. */
const BATCH = 2000

/**
 * Find the number the challenge was made from, and return the answer in the form the
 * server expects. Rejects if the challenge is not solvable or the work was cancelled.
 */
export async function solveChallenge(challenge: PowChallenge, signal?: AbortSignal): Promise<string> {
	if (challenge.algorithm !== 'SHA-256') {
		throw new Error('unsupported challenge')
	}
	for (let start = 0; start <= challenge.maxnumber; start += BATCH) {
		if (signal?.aborted) {
			throw new Error('cancelled')
		}
		const end = Math.min(start + BATCH, challenge.maxnumber + 1)
		for (let number = start; number < end; number++) {
			if (sha256Hex(challenge.salt + number) === challenge.challenge) {
				return btoa(JSON.stringify({
					algorithm: challenge.algorithm,
					challenge: challenge.challenge,
					number,
					salt: challenge.salt,
					signature: challenge.signature,
				}))
			}
		}
		await new Promise((resolve) => setTimeout(resolve, 0))
	}
	throw new Error('unsolvable')
}
