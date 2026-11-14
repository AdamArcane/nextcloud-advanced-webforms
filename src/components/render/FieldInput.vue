<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { FormField, LabelPosition } from '../../types.ts'

import { computed } from 'vue'
import MarkdownText from './MarkdownText.ts'

const props = defineProps<{
	/** Stable field id, used for DOM ids. */
	fieldId: string
	field: FormField
	modelValue: unknown
	required: boolean
	/** Already translated message, or null. */
	error: string | null
	/** The position to draw, after fallbacks (see renderedLabelPosition). */
	labelPosition?: LabelPosition
}>()

const emit = defineEmits<{
	'update:modelValue': [value: unknown]
}>()

const hiddenLabel = computed(() => props.labelPosition === 'hidden')
const inlineLabel = computed(() => props.labelPosition === 'inline')

const domId = computed(() => 'af-' + props.fieldId)
const describedBy = computed(() => {
	const ids: string[] = []
	if (props.field.description) {
		ids.push(domId.value + '-desc')
	}
	if (props.error) {
		ids.push(domId.value + '-err')
	}
	return ids.length > 0 ? ids.join(' ') : undefined
})

const isDisplay = computed(() => ['heading', 'paragraph', 'divider'].includes(props.field.type))
const isGroup = computed(() => props.field.type === 'radio' || props.field.type === 'checkboxes')
const inputType = computed(() => ({
	text: 'text',
	email: 'email',
	phone: 'tel',
	number: 'number',
	date: 'date',
	time: 'time',
}[props.field.type] ?? 'text'))
const headingTag = computed(() => 'h' + ([2, 3, 4].includes(props.field.level ?? 2) ? (props.field.level ?? 2) : 2))

const stringValue = computed(() => (typeof props.modelValue === 'string' ? props.modelValue : ''))
const listValue = computed(() => (Array.isArray(props.modelValue) ? props.modelValue as string[] : []))

function onInput(event: Event) {
	emit('update:modelValue', (event.target as HTMLInputElement).value)
}

function onCheckbox(event: Event) {
	emit('update:modelValue', (event.target as HTMLInputElement).checked)
}

function toggleOption(value: string, event: Event) {
	const checked = (event.target as HTMLInputElement).checked
	const next = listValue.value.filter((item) => item !== value)
	if (checked) {
		next.push(value)
	}
	emit('update:modelValue', next)
}
</script>

<template>
	<!-- Display types: no value, no label. -->
	<template v-if="isDisplay">
		<component :is="headingTag" v-if="field.type === 'heading'" class="af-heading">
			{{ field.text }}
		</component>
		<MarkdownText v-else-if="field.type === 'paragraph'" :source="field.text ?? ''" />
		<hr v-else class="af-divider">
	</template>

	<div v-else
		class="af-field"
		:data-field="fieldId"
		:data-label-position="props.labelPosition ?? 'above'"
		:class="{ 'af-field--error': error, 'af-field--inline': inlineLabel }">
		<!-- A single checkbox carries its own label text. -->
		<template v-if="field.type === 'checkbox'">
			<label class="af-checkbox">
				<input :id="domId"
					type="checkbox"
					:checked="modelValue === true"
					:aria-required="required || undefined"
					:aria-invalid="error ? true : undefined"
					:aria-describedby="describedBy"
					@change="onCheckbox">
				<span :class="{ 'af-sr-only': hiddenLabel }">{{ field.label }}<span v-if="required" aria-hidden="true"> *</span></span>
			</label>
		</template>

		<!-- Groups use fieldset and legend so the label names the whole group. -->
		<fieldset v-else-if="isGroup" class="af-group" :aria-describedby="describedBy" :aria-invalid="error ? true : undefined">
			<legend :class="{ 'af-sr-only': hiddenLabel }">
				{{ field.label }}<span v-if="required" aria-hidden="true"> *</span>
			</legend>
			<p v-if="field.description" :id="domId + '-desc'" class="af-description">
				{{ field.description }}
			</p>
			<label v-for="option in field.options" :key="option.value" class="af-checkbox">
				<input v-if="field.type === 'radio'"
					type="radio"
					:name="domId"
					:value="option.value"
					:checked="modelValue === option.value"
					@change="emit('update:modelValue', option.value)">
				<input v-else
					type="checkbox"
					:value="option.value"
					:checked="listValue.includes(option.value)"
					@change="toggleOption(option.value, $event)">
				<span>{{ option.label }}</span>
			</label>
		</fieldset>

		<template v-else>
			<label :for="domId" class="af-label" :class="{ 'af-sr-only': hiddenLabel }">
				{{ field.label }}<span v-if="required" aria-hidden="true"> *</span>
			</label>
			<textarea v-if="field.type === 'textarea'"
				:id="domId"
				rows="5"
				:value="stringValue"
				:placeholder="field.placeholder"
				:maxlength="field.maxLength"
				:aria-required="required || undefined"
				:aria-invalid="error ? true : undefined"
				:aria-describedby="describedBy"
				@input="onInput" />
			<select v-else-if="field.type === 'select'"
				:id="domId"
				:value="stringValue"
				:aria-required="required || undefined"
				:aria-invalid="error ? true : undefined"
				:aria-describedby="describedBy"
				@change="onInput">
				<option value="">
					{{ field.placeholder ?? '' }}
				</option>
				<option v-for="option in field.options" :key="option.value" :value="option.value">
					{{ option.label }}
				</option>
			</select>
			<input v-else
				:id="domId"
				:type="inputType"
				:value="stringValue"
				:placeholder="field.placeholder"
				:maxlength="field.type === 'text' ? field.maxLength : undefined"
				:min="field.type === 'number' ? field.min : undefined"
				:max="field.type === 'number' ? field.max : undefined"
				:step="field.type === 'number' ? (field.step ?? 'any') : undefined"
				:aria-required="required || undefined"
				:aria-invalid="error ? true : undefined"
				:aria-describedby="describedBy"
				@input="onInput">
		</template>

		<p v-if="field.description && !isGroup" :id="domId + '-desc'" class="af-description">
			{{ field.description }}
		</p>
		<p v-if="error" :id="domId + '-err'" class="af-error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<style scoped>
.af-field {
	margin-bottom: 16px;
}

/* Label beside the input. The label takes a fixed share, the input and its help and error text share the rest. */
.af-field--inline {
	display: grid;
	grid-template-columns: minmax(7rem, 32%) minmax(0, 1fr);
	column-gap: 16px;
	align-items: start;
}

.af-field--inline > .af-label {
	grid-column: 1;
	margin: 0;
	/* Line the text up with the middle of the input. */
	padding-top: calc((var(--default-clickable-area, 34px) - 1.5em) / 2);
	overflow-wrap: anywhere;
}

.af-field--inline > :not(.af-label) {
	grid-column: 2;
}

.af-field--inline > .af-description,
.af-field--inline > .af-error {
	margin-top: 0;
}

/* A narrow field (a quarter-width cell, or a phone) gets its label above instead (docs/SCHEMA.md 4.2). */
@container (max-width: 380px) {
	.af-field--inline {
		display: block;
	}

	.af-field--inline > .af-label {
		margin-bottom: 4px;
		padding-top: 0;
	}
}

.af-label,
.af-group legend {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.af-group {
	border: 0;
	margin: 0;
	padding: 0;
}

.af-field input:not([type='checkbox'], [type='radio']),
.af-field textarea,
.af-field select {
	width: 100%;
	box-sizing: border-box;
}

.af-checkbox {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: var(--default-clickable-area, 34px);
}

.af-checkbox input {
	margin: 0;
	flex: none;
}

.af-description {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.af-error {
	margin: 4px 0 0;
	color: var(--color-error-text, var(--color-error));
}

.af-field--error input:not([type='checkbox'], [type='radio']),
.af-field--error textarea,
.af-field--error select {
	border-color: var(--color-error);
}

.af-heading {
	margin: 8px 0;
}

.af-divider {
	margin: 16px 0;
	border: 0;
	border-top: 1px solid var(--color-border);
}

/* Hidden visually but still announced by screen readers. */
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
