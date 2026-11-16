<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { FormField, LabelPosition } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { describeError } from '../../builder/errors.ts'
import { fieldTypeName } from '../../builder/fieldTypeNames.ts'
import { fieldTypeIcon, icons } from '../../icons.ts'
import { steps } from '../../rules/fieldTypes.ts'
import { ownerImageUrl } from '../../images.ts'
import Icon from '../common/Icon.vue'
import MarkdownText from '../render/MarkdownText.ts'
import '../render/typography.css'

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

const isDisplay = computed(() => ['heading', 'paragraph', 'divider', 'image'].includes(props.field.type))
const imageUrl = computed(() => (props.field.type === 'image' ? ownerImageUrl(props.field.src ?? '') : null))
const required = computed(() => props.field.required === true)
const hasOptions = computed(() => ['select', 'radio', 'checkboxes'].includes(props.field.type))
const hiddenLabel = computed(() => props.labelPosition === 'hidden')
const inlineLabel = computed(() => props.labelPosition === 'inline')

const typeLabel = computed(() => fieldTypeName(props.field.type))

const alignStyle = computed(() => (props.field.align === 'center' || props.field.align === 'end' ? { textAlign: props.field.align } : undefined))

const headingTag = computed(() => 'h' + ([1, 2, 3, 4, 5, 6].includes(props.field.level ?? 2) ? (props.field.level ?? 2) : 2))

/** Radio buttons and checkboxes in as many columns as the field asks for. */
const columnStyle = computed(() => (props.field.columns && props.field.columns > 1
	? { display: 'grid', gridTemplateColumns: `repeat(${props.field.columns}, minmax(0, 1fr))`, columnGap: '16px' }
	: undefined))
const shownOptions = computed(() => 3 * Math.max(1, props.field.columns ?? 1))
/** A textarea is as tall as the lines it shows. */
const areaStyle = computed(() => (props.field.type === 'textarea'
	? { minHeight: `calc(${Math.min(20, Math.max(2, props.field.rows ?? 5))} * 1.5em + 16px)` }
	: undefined))

/** A text-like default, shown in the preview box the way the form will start. */
const defaultText = computed(() => {
	const value = props.field.defaultValue
	if (typeof value === 'number') {
		return String(value)
	}
	if (typeof value !== 'string' || value === '') {
		return ''
	}
	return props.field.type === 'select' ? (props.field.options ?? []).find((option) => option.value === value)?.label ?? '' : value
})
const title = computed(() => props.field.label || props.field.text || typeLabel.value)
</script>

<template>
	<div class="af-card"
		:class="{ 'af-card--selected': selected, 'af-card--error': errors.length > 0 }"
		role="button"
		tabindex="0"
		:aria-pressed="selected"
		:aria-label="t('webforms', 'Edit field: {name}', { name: title })"
		@click="emit('select')"
		@keydown.enter.self.prevent="emit('select')"
		@keydown.space.self.prevent="emit('select')">
		<div class="af-card__meta">
			<Icon class="af-card__typeicon" :path="fieldTypeIcon(field.type)" :size="16" />
			<span class="af-card__type">{{ typeLabel }}</span>
			<span v-if="ruleCount > 0" class="af-card__badge" :title="t('webforms', 'This field has conditional logic')">
				{{ t('webforms', 'Conditional') }}
			</span>
			<span v-if="hiddenLabel" class="af-card__badge af-card__badge--quiet">
				{{ t('webforms', 'Label hidden') }}
			</span>
			<span v-else-if="inlineLabel" class="af-card__badge af-card__badge--quiet">
				{{ t('webforms', 'Label beside') }}
			</span>
		</div>

		<template v-if="isDisplay">
			<hr v-if="field.type === 'divider'"
				class="af-divider af-card__divider"
				:class="field.style && field.style !== 'solid' ? 'af-divider--' + field.style : undefined">
			<component :is="headingTag" v-else-if="field.type === 'heading'" class="af-heading" :style="alignStyle">
				{{ field.text }}
			</component>
			<div v-else-if="field.type === 'image'" class="af-card__image" :style="alignStyle">
				<img v-if="imageUrl"
					:src="imageUrl"
					:alt="field.alt ?? ''"
					:style="{ maxWidth: (field.width ?? 100) + '%' }"
					referrerpolicy="no-referrer">
				<span v-else class="af-card__upload">
					<Icon :path="fieldTypeIcon('image')" :size="18" />
					{{ t('webforms', 'Choose a picture') }}
				</span>
			</div>
			<MarkdownText v-else :source="field.text ?? ''" :style="alignStyle" />
		</template>

		<div v-else class="af-card__body" :class="{ 'af-card__body--inline': inlineLabel }">
			<span class="af-card__label" :class="{ 'af-card__label--hidden': hiddenLabel }">
				{{ field.label || t('webforms', 'Untitled field') }}<span v-if="required && !hideRequiredIndicator" class="af-card__star" aria-hidden="true"> *</span>
			</span>
			<span class="af-card__control">
				<span v-if="field.type === 'checkbox'" class="af-card__fake af-card__fake--box" aria-hidden="true" />
				<span v-else-if="field.type === 'consent'" class="af-card__consent" aria-hidden="true">
					<span class="af-card__fake af-card__fake--box" />
					<MarkdownText :source="field.text ?? ''" />
				</span>
				<span v-else-if="field.type === 'rating'" class="af-card__stars" aria-hidden="true">
					<Icon v-for="value in steps(field)" :key="value" :path="icons.star" :size="24" />
				</span>
				<span v-else-if="field.type === 'scale'" class="af-card__scale" aria-hidden="true">
					<span class="af-card__steps">
						<span v-for="value in steps(field)" :key="value" class="af-card__step">{{ value }}</span>
					</span>
					<span v-if="field.minLabel || field.maxLabel" class="af-card__ends">
						<span>{{ field.minLabel }}</span>
						<span>{{ field.maxLabel }}</span>
					</span>
				</span>
				<span v-else-if="field.type === 'hidden'" class="af-card__upload af-card__upload--hidden">
					<Icon :path="fieldTypeIcon('hidden')" :size="18" />
					<span>
						{{ t('webforms', 'Not shown to respondents.') }}
						{{ typeof field.defaultValue === 'string' && field.defaultValue !== ''
							? t('webforms', 'Default: {value}', { value: field.defaultValue })
							: t('webforms', 'Filled from the link, or give it a default.') }}
					</span>
				</span>
				<span v-else-if="field.type === 'file'" class="af-card__upload" aria-hidden="true">
					<Icon :path="fieldTypeIcon('file')" :size="18" />
					{{ (field.maxFiles ?? 1) > 1 ? t('webforms', 'Choose files') : t('webforms', 'Choose a file') }}
				</span>
				<span v-else-if="hasOptions && field.type !== 'select'" class="af-card__choices" :style="columnStyle" aria-hidden="true">
					<span v-for="option in (field.options ?? []).slice(0, shownOptions)" :key="option.value" class="af-card__choice">
						<span class="af-card__dot" :class="{ 'af-card__dot--square': field.type === 'checkboxes' }" />{{ option.label }}
					</span>
					<span v-if="(field.options ?? []).length > shownOptions" class="af-card__more">+{{ (field.options ?? []).length - shownOptions }}</span>
				</span>
				<span v-else
					class="af-card__fake"
					:class="{ 'af-card__fake--area': field.type === 'textarea' }"
					:style="areaStyle"
					aria-hidden="true">
					<span v-if="field.type === 'number' && field.prefix" class="af-card__affix">{{ field.prefix }}</span>
					<span v-if="defaultText" class="af-card__default">{{ defaultText }}</span>
					<span v-else class="af-card__placeholder">{{ field.placeholder }}</span>
					<span v-if="field.type === 'number' && field.suffix" class="af-card__affix af-card__affix--end">{{ field.suffix }}</span>
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

.af-card__image img {
	max-width: 100%;
	height: auto;
	vertical-align: middle;
}

.af-card__consent {
	display: flex;
	align-items: flex-start;
	gap: 8px;
}

.af-card__consent :deep(p) {
	margin: 0;
}

.af-card__stars {
	display: flex;
	gap: 2px;
	color: var(--color-border-maxcontrast, var(--color-border-dark));
}

.af-card__scale {
	display: block;
}

.af-card__steps {
	display: flex;
	gap: 4px;
}

.af-card__step {
	display: flex;
	flex: 1 1 0;
	align-items: center;
	justify-content: center;
	max-width: 56px;
	min-height: 30px;
	border: 2px solid var(--color-border-maxcontrast, var(--color-border-dark));
	border-radius: var(--border-radius-element, 8px);
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.af-card__ends {
	display: flex;
	justify-content: space-between;
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
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

.af-card__upload--hidden {
	justify-content: flex-start;
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
	text-align: start;
}

.af-card__default {
	color: var(--color-main-text);
}

.af-card__affix {
	margin-inline-end: calc(var(--default-grid-baseline) * 2);
}

.af-card__affix--end {
	margin-inline: auto 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 2);
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

/* The preview shows the real thing, so the card adds no spacing of its own around it. */
.af-card :deep(.af-heading) {
	margin: 0;
}

.af-card__errors {
	margin: 0;
	padding: 0;
	list-style: none;
	color: var(--color-error-text);
	font-size: 13px;
}
</style>
