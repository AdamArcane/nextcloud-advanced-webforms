<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { FormField, LabelPosition } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { describeError } from '../../builder/errors.ts'
import { fieldTypeIcon } from '../../icons.ts'
import Icon from '../common/Icon.vue'

const props = defineProps<{
	field: FormField
	selected: boolean
	/** Number of rules that show, hide or require this field. */
	ruleCount: number
	errors: string[]
	/** The position respondents will see, after the form default and fallbacks. */
	labelPosition: LabelPosition
	hideRequiredIndicator?: boolean
}>()

const emit = defineEmits<{
	select: []
}>()

const isDisplay = computed(() => ['heading', 'paragraph', 'divider'].includes(props.field.type))
const required = computed(() => props.field.required === true)
const hasOptions = computed(() => ['select', 'radio', 'checkboxes'].includes(props.field.type))
const hiddenLabel = computed(() => props.labelPosition === 'hidden')
const inlineLabel = computed(() => props.labelPosition === 'inline')

const typeLabel = computed(() => {
	switch (props.field.type) {
		case 'text': return t('advancedforms', 'Short text')
		case 'textarea': return t('advancedforms', 'Long text')
		case 'email': return t('advancedforms', 'Email')
		case 'phone': return t('advancedforms', 'Phone')
		case 'number': return t('advancedforms', 'Number')
		case 'select': return t('advancedforms', 'Dropdown')
		case 'radio': return t('advancedforms', 'Single choice')
		case 'checkboxes': return t('advancedforms', 'Multiple choice')
		case 'checkbox': return t('advancedforms', 'Checkbox')
		case 'date': return t('advancedforms', 'Date')
		case 'time': return t('advancedforms', 'Time')
		case 'file': return t('advancedforms', 'File upload')
		case 'heading': return t('advancedforms', 'Heading')
		case 'paragraph': return t('advancedforms', 'Paragraph')
		default: return t('advancedforms', 'Divider')
	}
})

const alignStyle = computed(() => (props.field.align === 'center' || props.field.align === 'end' ? { textAlign: props.field.align } : undefined))

const title = computed(() => props.field.label || props.field.text || typeLabel.value)
</script>

<template>
	<div class="af-card"
		:class="{ 'af-card--selected': selected, 'af-card--error': errors.length > 0 }"
		role="button"
		tabindex="0"
		:aria-pressed="selected"
		:aria-label="t('advancedforms', 'Edit field: {name}', { name: title })"
		@click="emit('select')"
		@keydown.enter.self.prevent="emit('select')"
		@keydown.space.self.prevent="emit('select')">
		<div class="af-card__meta">
			<Icon class="af-card__typeicon" :path="fieldTypeIcon(field.type)" :size="16" />
			<span class="af-card__type">{{ typeLabel }}</span>
			<span v-if="ruleCount > 0" class="af-card__badge" :title="t('advancedforms', 'This field has conditional logic')">
				{{ t('advancedforms', 'Conditional') }}
			</span>
			<span v-if="hiddenLabel" class="af-card__badge af-card__badge--quiet">
				{{ t('advancedforms', 'Label hidden') }}
			</span>
			<span v-else-if="inlineLabel" class="af-card__badge af-card__badge--quiet">
				{{ t('advancedforms', 'Label beside') }}
			</span>
		</div>

		<template v-if="isDisplay">
			<hr v-if="field.type === 'divider'" class="af-card__divider">
			<p v-else-if="field.type === 'heading'" class="af-card__heading" :style="alignStyle">
				{{ field.text }}
			</p>
			<p v-else class="af-card__text" :style="alignStyle">
				{{ field.text }}
			</p>
		</template>

		<div v-else class="af-card__body" :class="{ 'af-card__body--inline': inlineLabel }">
			<span class="af-card__label" :class="{ 'af-card__label--hidden': hiddenLabel }">
				{{ field.label || t('advancedforms', 'Untitled field') }}<span v-if="required && !hideRequiredIndicator" class="af-card__star" aria-hidden="true"> *</span>
			</span>
			<span class="af-card__control">
				<span v-if="field.type === 'checkbox'" class="af-card__fake af-card__fake--box" aria-hidden="true" />
				<span v-else-if="field.type === 'file'" class="af-card__upload" aria-hidden="true">
					<Icon :path="fieldTypeIcon('file')" :size="18" />
					{{ (field.maxFiles ?? 1) > 1 ? t('advancedforms', 'Choose files') : t('advancedforms', 'Choose a file') }}
				</span>
				<span v-else-if="hasOptions && field.type !== 'select'" class="af-card__choices" aria-hidden="true">
					<span v-for="option in (field.options ?? []).slice(0, 3)" :key="option.value" class="af-card__choice">
						<span class="af-card__dot" :class="{ 'af-card__dot--square': field.type === 'checkboxes' }" />{{ option.label }}
					</span>
					<span v-if="(field.options ?? []).length > 3" class="af-card__more">+{{ (field.options ?? []).length - 3 }}</span>
				</span>
				<span v-else
					class="af-card__fake"
					:class="{ 'af-card__fake--area': field.type === 'textarea' }"
					aria-hidden="true">
					<span class="af-card__placeholder">{{ field.placeholder }}</span>
					<span v-if="field.type === 'select'" class="af-card__chevron" />
				</span>
				<span v-if="field.description" class="af-card__help">{{ field.description }}</span>
			</span>
		</div>

		<ul v-if="errors.length > 0" class="af-card__errors">
			<li v-for="code in errors" :key="code">
				{{ describeError(code) }}
			</li>
		</ul>
	</div>
</template>

<style scoped>
.af-card {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 1);
	height: 100%;
	box-sizing: border-box;
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	cursor: pointer;
	transition: border-color 0.12s ease, box-shadow 0.12s ease, background-color 0.12s ease;
}

.af-card:hover {
	border-color: var(--color-border-maxcontrast);
	background: var(--color-background-hover);
}

.af-card:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.af-card--selected,
.af-card--selected:hover {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element);
	background: var(--color-main-background);
}

.af-card--error {
	border-color: var(--color-error);
}

.af-card__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
	min-height: 20px;
}

.af-card__typeicon {
	color: var(--color-text-maxcontrast);
}

.af-card__type {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.af-card__badge {
	padding: 0 calc(var(--default-grid-baseline) * 1.5);
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 12px;
	line-height: 20px;
}

.af-card__badge--quiet {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.af-card__body {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 1);
}

.af-card__control {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 1);
	min-width: 0;
}

/* The same side-by-side arrangement as the public form, and the same fallback when the card is narrow. */
.af-card__body--inline {
	display: grid;
	grid-template-columns: minmax(4.5rem, 32%) minmax(0, 1fr);
	column-gap: calc(var(--default-grid-baseline) * 3);
	align-items: start;
}

.af-card__body--inline > .af-card__label {
	padding-top: calc((var(--default-clickable-area) - 1.5em) / 2);
}

@container (max-width: 380px) {
	.af-card__body--inline {
		display: flex;
		flex-direction: column;
	}

	.af-card__body--inline > .af-card__label {
		padding-top: 0;
	}
}

.af-card__label {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.af-card__label--hidden {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
	text-decoration-color: var(--color-border-maxcontrast);
}

.af-card__star {
	color: var(--color-error-text);
}

.af-card__help {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.af-card__fake {
	display: flex;
	align-items: center;
	justify-content: space-between;
	min-height: var(--default-clickable-area);
	padding: 0 calc(var(--default-grid-baseline) * 3);
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element);
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
}

.af-card__fake--area {
	align-items: flex-start;
	min-height: calc(var(--default-clickable-area) * 2);
	padding-top: calc(var(--default-grid-baseline) * 2);
}

.af-card__fake--box {
	width: 20px;
	min-height: 20px;
	padding: 0;
	border-radius: var(--border-radius-small);
}

.af-card__upload {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 2px dashed var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	color: var(--color-text-maxcontrast);
}

.af-card__chevron {
	width: 8px;
	height: 8px;
	border-right: 2px solid currentcolor;
	border-bottom: 2px solid currentcolor;
	transform: rotate(45deg) translate(-2px, -2px);
}

.af-card__choices {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
}

.af-card__choice {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.af-card__dot {
	flex: none;
	width: 16px;
	height: 16px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: 50%;
}

.af-card__dot--square {
	border-radius: var(--border-radius-small);
}

.af-card__more {
	font-size: 13px;
}

.af-card__divider {
	width: 100%;
	margin: calc(var(--default-grid-baseline) * 2) 0;
	border: 0;
	border-top: 1px solid var(--color-border-maxcontrast);
}

.af-card__heading {
	margin: 0;
	font-size: 20px;
	font-weight: bold;
}

.af-card__text {
	margin: 0;
	white-space: pre-line;
	color: var(--color-text-maxcontrast);
}

.af-card__errors {
	margin: 0;
	padding: 0;
	list-style: none;
	color: var(--color-error-text);
	font-size: 13px;
}
</style>
