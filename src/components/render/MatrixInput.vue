<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { FormField } from '../../types.ts'

import { computed } from 'vue'

// A grid of choices: a row for every question, a column for every option (docs/SCHEMA.md 4.10).
// One option per row, or several when the field allows `multiple`. The answer is an object from the
// row's value to the picked column's value (or a list of them).

const props = defineProps<{
	fieldId: string
	field: FormField
	modelValue: unknown
	describedBy?: string
	required?: boolean
}>()

const emit = defineEmits<{
	'update:modelValue': [value: Record<string, unknown>]
}>()

const answers = computed<Record<string, unknown>>(() => (typeof props.modelValue === 'object' && props.modelValue !== null && !Array.isArray(props.modelValue) ? props.modelValue as Record<string, unknown> : {}))
const multiple = computed(() => props.field.multiple === true)

function picked(row: string, column: string): boolean {
	const answer = answers.value[row]
	return Array.isArray(answer) ? answer.includes(column) : answer === column
}

function choose(row: string, column: string, checked: boolean) {
	const next = { ...answers.value }
	if (multiple.value) {
		const current = Array.isArray(next[row]) ? (next[row] as string[]) : []
		const list = current.filter((entry) => entry !== column)
		if (checked) {
			list.push(column)
		}
		next[row] = list
	} else {
		next[row] = column
	}
	emit('update:modelValue', next)
}
</script>

<template>
	<div class="af-matrix" :aria-describedby="describedBy">
		<table>
			<thead>
				<tr>
					<td />
					<th v-for="column in field.options ?? []" :key="column.value" scope="col">
						{{ column.label }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="question in field.questions ?? []" :key="question.value">
					<th :id="'af-' + fieldId + '-' + question.value" scope="row">
						{{ question.label }}
					</th>
					<td v-for="column in field.options ?? []" :key="column.value">
						<label class="af-matrix__cell">
							<input :type="multiple ? 'checkbox' : 'radio'"
								:name="'af-' + fieldId + '-' + question.value"
								:value="column.value"
								:checked="picked(question.value, column.value)"
								:aria-required="required && !multiple ? true : undefined"
								@change="choose(question.value, column.value, ($event.target as HTMLInputElement).checked)">
							<span class="af-sr-only">{{ column.label }}</span>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<style scoped>
/* A wide grid scrolls inside its own box instead of pushing the page sideways. */
.af-matrix {
	overflow-x: auto;
}

.af-matrix table {
	border-collapse: collapse;
	min-width: 100%;
}

.af-matrix th,
.af-matrix td {
	padding: 4px 8px;
	text-align: center;
	vertical-align: middle;
}

.af-matrix thead th {
	font-weight: 600;
	min-width: 5rem;
}

.af-matrix tbody th {
	min-width: 8rem;
	text-align: start;
	font-weight: normal;
}

.af-matrix tbody tr:nth-child(even) {
	background: var(--color-background-hover);
}

.af-matrix__cell {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: var(--default-clickable-area, 34px);
	cursor: pointer;
}

.af-matrix__cell input {
	margin: 0;
}

.af-sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}
</style>
