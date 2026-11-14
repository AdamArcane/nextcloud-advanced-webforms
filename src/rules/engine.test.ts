/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Rule, RuleDefinition } from './types.ts'

import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { evaluateRules } from './engine.ts'
import { RuleCycleError } from './graph.ts'

interface VectorCase {
	name: string
	fields: RuleDefinition['fields']
	rules: Rule[]
	values: Record<string, unknown>
	expect: { visible: string[], required: string[] }
}

// The same file the PHP tests run (tests/Unit/Rules/VectorsTest.php).
const vectors = JSON.parse(
	readFileSync(new URL('../../tests/vectors/rules.json', import.meta.url), 'utf-8'),
) as { cases: VectorCase[] }

describe('shared rule vectors', () => {
	it('has a meaningful number of cases', () => {
		expect(vectors.cases.length).toBeGreaterThan(50)
	})

	for (const testCase of vectors.cases) {
		it(testCase.name, () => {
			const result = evaluateRules(
				{ fields: testCase.fields, rules: testCase.rules },
				testCase.values,
			)
			expect([...result.visible].sort()).toEqual(testCase.expect.visible)
			expect([...result.required].sort()).toEqual(testCase.expect.required)
		})
	}
})

describe('evaluateRules', () => {
	it('refuses cyclic definitions', () => {
		const definition: RuleDefinition = {
			fields: { f_name: { type: 'text' } },
			rules: [{
				id: 'rule_1',
				effect: 'show',
				targets: ['f_name'],
				when: { all: [{ field: 'f_name', op: 'not_empty' }] },
			}],
		}
		expect(() => evaluateRules(definition, {})).toThrow(RuleCycleError)
	})

	it('ignores inherited object properties as values', () => {
		const definition: RuleDefinition = {
			fields: { constructor: { type: 'text', required: true } },
		}
		const result = evaluateRules(definition, {})
		expect(result.required).toEqual(['constructor'])
	})

	it('does not mutate its input', () => {
		const definition: RuleDefinition = {
			fields: { f_a: { type: 'text' } },
			rules: [],
		}
		const values = { f_a: 'x' }
		const before = JSON.stringify({ definition, values })
		evaluateRules(definition, values)
		expect(JSON.stringify({ definition, values })).toBe(before)
	})
})
