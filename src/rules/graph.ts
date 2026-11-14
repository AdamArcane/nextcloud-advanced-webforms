/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Rule } from './types.ts'

// Mirrors lib/Rules/RuleGraph.php (docs/SCHEMA.md section 6.4).

export class RuleCycleError extends Error {
	constructor(message: string) {
		super(message)
		this.name = 'RuleCycleError'
	}
}

/** Field IDs read by a condition tree, in order of appearance. */
export function sourcesOf(node: unknown): string[] {
	if (typeof node !== 'object' || node === null) {
		return []
	}
	const record = node as Record<string, unknown>
	const sources: string[] = []
	for (const key of ['all', 'any']) {
		const members = record[key]
		if (Array.isArray(members)) {
			for (const member of members) {
				sources.push(...sourcesOf(member))
			}
		}
	}
	if (typeof record.field === 'string') {
		sources.push(record.field)
	}
	return sources
}

/**
 * Field IDs ordered so every field comes after the fields its visibility and
 * required state depend on. Ties keep document order.
 *
 * @param fieldIds in document order
 * @param rules the form's rules
 * @throws RuleCycleError when rules depend on each other in a loop
 */
export function evaluationOrder(fieldIds: string[], rules: Rule[]): string[] {
	const known = new Set(fieldIds)
	const dependsOn = new Map<string, Set<string>>()

	for (const rule of rules) {
		const sources = sourcesOf(rule.when)
		const targets = Array.isArray(rule.targets) ? rule.targets : []
		for (const target of targets) {
			if (typeof target !== 'string' || !known.has(target)) {
				continue
			}
			for (const source of sources) {
				if (known.has(source)) {
					const set = dependsOn.get(target) ?? new Set<string>()
					set.add(source)
					dependsOn.set(target, set)
				}
			}
		}
	}

	const order: string[] = []
	const placed = new Set<string>()
	let remaining = fieldIds
	while (remaining.length > 0) {
		let progress = false
		const next: string[] = []
		for (const id of remaining) {
			const deps = dependsOn.get(id)
			const ready = deps === undefined || [...deps].every((source) => placed.has(source))
			if (ready) {
				placed.add(id)
				order.push(id)
				progress = true
			} else {
				next.push(id)
			}
		}
		if (!progress) {
			throw new RuleCycleError('Rules depend on each other in a loop: ' + next.join(', '))
		}
		remaining = next
	}

	return order
}
