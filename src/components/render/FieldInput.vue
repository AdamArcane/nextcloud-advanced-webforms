<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { FormField, LabelPosition } from '../../types.ts'

import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { addDays } from '../../answers.ts'
import { icons } from '../../icons.ts'
import { isTemporalType, steps, temporalInputType } from '../../rules/fieldTypes.ts'
import FileInput from './FileInput.vue'
import MatrixInput from './MatrixInput.vue'
import PartsInput from './PartsInput.vue'
import SignatureInput from './SignatureInput.vue'
import type { UploadState } from './uploadState.ts'
import MarkdownText from './MarkdownText.ts'
import './typography.css'

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
	/** Leave out the asterisk after the label. The control is still marked required for assistive tech. */
	hideRequiredIndicator?: boolean
	/** What an upload field needs to send its files. */
	uploads?: UploadState
	signedIn?: boolean
	preview?: boolean
	uploadLimit?: number
	/** Where an image block's picture loads from, worked out by the form (it knows the token). */
	imageSrc?: string | null
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

const isDisplay = computed(() => ['heading', 'paragraph', 'divider', 'image'].includes(props.field.type))
const imageStyle = computed(() => ({ maxWidth: ([25, 50, 75].includes(props.field.width ?? 100) ? props.field.width : 100) + '%' }))
const isTemporal = computed(() => isTemporalType(props.field.type))
const isPick = computed(() => props.field.type === 'rating' || props.field.type === 'scale')
/** Answers with several parts or controls: a fieldset names the whole answer, like a group of options. */
const isCompound = computed(() => ['matrix', 'daterange', 'name', 'address', 'signature'].includes(props.field.type))
const rangeValue = computed<{ start: string, end: string }>(() => {
	const value = props.modelValue
	const given = typeof value === 'object' && value !== null && !Array.isArray(value) ? value as Record<string, unknown> : {}
	return { start: typeof given.start === 'string' ? given.start : '', end: typeof given.end === 'string' ? given.end : '' }
})
/** The earliest and latest last date, from the first date and the field's own limits. */
const rangeEndMin = computed(() => {
	const floor = textLimit(props.field.min)
	const start = rangeValue.value.start
	if (start === '') {
		return floor
	}
	const earliest = addDays(start, props.field.minDays ?? 0)
	return floor !== undefined && floor > earliest ? floor : earliest
})
const rangeEndMax = computed(() => {
	const ceiling = textLimit(props.field.max)
	const start = rangeValue.value.start
	if (start === '' || props.field.maxDays === undefined) {
		return ceiling
	}
	const latest = addDays(start, props.field.maxDays)
	return ceiling !== undefined && ceiling < latest ? ceiling : latest
})
const sliderMin = computed(() => (typeof props.field.min === 'number' ? props.field.min : 0))
const sliderMax = computed(() => (typeof props.field.max === 'number' ? props.field.max : 100))
const sliderStep = computed(() => (typeof props.field.step === 'number' && props.field.step > 0 ? props.field.step : 1))
const answered = computed(() => stringValue.value !== '')
const colorValue = computed(() => (/^#[0-9a-f]{6}$/i.test(stringValue.value) ? stringValue.value : '#000000'))
const isGroup = computed(() => props.field.type === 'radio' || props.field.type === 'checkboxes' || isPick.value)
const pickValues = computed(() => (isPick.value ? steps(props.field) : []))
/** The picked number of a rating or a scale, or null. Kept as text like every typed answer. */
const picked = computed(() => (typeof props.modelValue === 'string' && props.modelValue !== '' ? Number(props.modelValue) : null))
const inputType = computed(() => ({
	text: 'text',
	email: 'email',
	phone: 'tel',
	url: 'url',
	number: 'number',
	date: 'date',
	time: 'time',
	datetime: temporalInputType('datetime'),
}[props.field.type] ?? 'text'))
/** Only headings and paragraphs align; start is the default and needs no style. */
const alignStyle = computed(() => (props.field.align === 'center' || props.field.align === 'end' ? { textAlign: props.field.align } : undefined))
const headingTag = computed(() => 'h' + ([1, 2, 3, 4, 5, 6].includes(props.field.level ?? 2) ? (props.field.level ?? 2) : 2))

const columnStyle = computed(() => (props.field.columns && props.field.columns > 1 ? { gridTemplateColumns: `repeat(${props.field.columns}, minmax(0, 1fr))` } : undefined))
const numberLimit = (value: unknown) => (typeof value === 'number' ? value : undefined)
const textLimit = (value: unknown) => (typeof value === 'string' ? value : undefined)

const hasAffix = computed(() => props.field.type === 'number' && Boolean(props.field.prefix || props.field.suffix))

const stringValue = computed(() => (typeof props.modelValue === 'string' ? props.modelValue : ''))
const listValue = computed(() => (Array.isArray(props.modelValue) ? props.modelValue as string[] : []))

function onInput(event: Event) {
	emit('update:modelValue', (event.target as HTMLInputElement).value)
}

/** Pick a number; picking the one already chosen takes the answer back. */
function pick(value: number) {
	emit('update:modelValue', picked.value === value ? '' : String(value))
}

function setRange(key: 'start' | 'end', event: Event) {
	emit('update:modelValue', { ...rangeValue.value, [key]: (event.target as HTMLInputElement).value })
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
		<component :is="headingTag" v-if="field.type === 'heading'" class="af-heading" :style="alignStyle">
			{{ field.text }}
		</component>
		<MarkdownText v-else-if="field.type === 'paragraph'" :source="field.text ?? ''" :style="alignStyle" />
		<div v-else-if="field.type === 'image'" class="af-image" :style="alignStyle">
			<img v-if="imageSrc"
				:src="imageSrc"
				:alt="field.alt ?? ''"
				:style="imageStyle"
				loading="lazy"
				referrerpolicy="no-referrer">
		</div>
		<hr v-else class="af-divider" :class="field.style && field.style !== 'solid' ? 'af-divider--' + field.style : undefined">
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
				<span :class="{ 'af-sr-only': hiddenLabel }">{{ field.label }}<span v-if="required && !hideRequiredIndicator" aria-hidden="true"> *</span></span>
			</label>
		</template>

		<!-- Consent: the label names the question, the checkbox sits beside what is agreed to. -->
		<template v-else-if="field.type === 'consent'">
			<span class="af-label" :class="{ 'af-sr-only': hiddenLabel }">
				{{ field.label }}<span v-if="required && !hideRequiredIndicator" aria-hidden="true"> *</span>
			</span>
			<label class="af-checkbox af-consent">
				<input :id="domId"
					type="checkbox"
					:checked="modelValue === true"
					:aria-required="required || undefined"
					:aria-invalid="error ? true : undefined"
					:aria-describedby="describedBy"
					@change="onCheckbox">
				<MarkdownText class="af-consent__text" :source="field.text ?? ''" />
			</label>
		</template>

		<!-- A rating or a scale: one number picked from a row, as radio buttons underneath. -->
		<fieldset v-else-if="isPick" class="af-group" :aria-describedby="describedBy" :aria-invalid="error ? true : undefined">
			<legend :class="{ 'af-sr-only': hiddenLabel }">
				{{ field.label }}<span v-if="required && !hideRequiredIndicator" aria-hidden="true"> *</span>
			</legend>
			<p v-if="field.description" :id="domId + '-desc'" class="af-description">
				{{ field.description }}
			</p>
			<div v-if="field.type === 'rating'" class="af-rating">
				<label v-for="value in pickValues"
					:key="value"
					class="af-rating__star"
					:class="{ 'af-rating__star--on': picked !== null && value <= picked }">
					<input class="af-sr-only"
						type="radio"
						:name="domId"
						:value="value"
						:checked="picked === value"
						:aria-required="required || undefined"
						@click="pick(value)">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path :d="icons.star" /></svg>
					<span class="af-sr-only">{{ n('webforms', '%n star', '%n stars', value) }}</span>
				</label>
			</div>
			<div v-else class="af-scale">
				<div class="af-scale__row">
					<label v-for="value in pickValues"
						:key="value"
						class="af-scale__step"
						:class="{ 'af-scale__step--on': picked === value }">
						<input class="af-sr-only"
							type="radio"
							:name="domId"
							:value="value"
							:checked="picked === value"
							:aria-required="required || undefined"
							@click="pick(value)">
						<span>{{ value }}</span>
					</label>
				</div>
				<div v-if="field.minLabel || field.maxLabel" class="af-scale__ends">
					<span>{{ field.minLabel }}</span>
					<span>{{ field.maxLabel }}</span>
				</div>
			</div>
		</fieldset>

		<!-- A matrix, a date range, a name, an address or a signature: one answer in several parts. -->
		<fieldset v-else-if="isCompound" class="af-group" :aria-describedby="describedBy" :aria-invalid="error ? true : undefined">
			<legend :class="{ 'af-sr-only': hiddenLabel }">
				{{ field.label }}<span v-if="required && !hideRequiredIndicator" aria-hidden="true"> *</span>
			</legend>
			<p v-if="field.description" :id="domId + '-desc'" class="af-description">
				{{ field.description }}
			</p>
			<MatrixInput v-if="field.type === 'matrix'"
				:field-id="fieldId"
				:field="field"
				:model-value="modelValue"
				:required="required"
				@update:model-value="(value) => emit('update:modelValue', value)" />
			<PartsInput v-else-if="field.type === 'name' || field.type === 'address'"
				:field-id="fieldId"
				:field="field"
				:model-value="modelValue"
				:invalid="Boolean(error)"
				@update:model-value="(value) => emit('update:modelValue', value)" />
			<SignatureInput v-else-if="field.type === 'signature'"
				:model-value="stringValue"
				:invalid="Boolean(error)"
				:required="required"
				@update:model-value="(value) => emit('update:modelValue', value)" />
			<div v-else class="af-range">
				<label>
					<span class="af-range__label">{{ t('webforms', 'From') }}</span>
					<input :id="domId"
						type="date"
						:value="rangeValue.start"
						:min="textLimit(field.min)"
						:max="rangeValue.end !== '' ? rangeValue.end : textLimit(field.max)"
						:aria-required="required || undefined"
						:aria-invalid="error ? true : undefined"
						@input="setRange('start', $event)">
				</label>
				<label>
					<span class="af-range__label">{{ t('webforms', 'To') }}</span>
					<input type="date"
						:value="rangeValue.end"
						:min="rangeEndMin"
						:max="rangeEndMax"
						:aria-required="required || undefined"
						:aria-invalid="error ? true : undefined"
						@input="setRange('end', $event)">
				</label>
			</div>
		</fieldset>

		<!-- Groups use fieldset and legend so the label names the whole group. -->
		<fieldset v-else-if="isGroup" class="af-group" :aria-describedby="describedBy" :aria-invalid="error ? true : undefined">
			<legend :class="{ 'af-sr-only': hiddenLabel }">
				{{ field.label }}<span v-if="required && !hideRequiredIndicator" aria-hidden="true"> *</span>
			</legend>
			<p v-if="field.description" :id="domId + '-desc'" class="af-description">
				{{ field.description }}
			</p>
			<div class="af-options" :style="columnStyle">
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
			</div>
		</fieldset>

		<template v-else>
			<label :for="domId" class="af-label" :class="{ 'af-sr-only': hiddenLabel }">
				{{ field.label }}<span v-if="required && !hideRequiredIndicator" aria-hidden="true"> *</span>
			</label>
			<FileInput v-if="field.type === 'file' && uploads"
				:field-id="fieldId"
				:field="field"
				:uploads="uploads"
				:signed-in="signedIn === true"
				:preview="preview === true"
				:limit="uploadLimit"
				:described-by="describedBy"
				:required="required" />
			<textarea v-else-if="field.type === 'textarea'"
				:id="domId"
				:rows="field.rows ?? 5"
				:minlength="field.minLength"
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
			<div v-else-if="field.type === 'slider'" class="af-slider">
				<input :id="domId"
					type="range"
					class="af-slider__range"
					:class="{ 'af-slider__range--unset': !answered }"
					:min="sliderMin"
					:max="sliderMax"
					:step="sliderStep"
					:value="answered ? stringValue : sliderMin"
					:aria-required="required || undefined"
					:aria-invalid="error ? true : undefined"
					:aria-describedby="describedBy"
					@input="onInput">
				<output class="af-slider__value" :for="domId">
					<template v-if="answered">{{ field.prefix }}{{ stringValue }}{{ field.suffix }}</template>
					<template v-else>{{ t('webforms', 'Not set') }}</template>
				</output>
				<button v-if="answered" type="button" class="af-slider__clear" @click="emit('update:modelValue', '')">
					{{ t('webforms', 'Clear') }}
				</button>
			</div>
			<div v-else-if="field.type === 'color'" class="af-colour">
				<input :id="domId"
					type="color"
					:value="colorValue"
					:aria-required="required || undefined"
					:aria-invalid="error ? true : undefined"
					:aria-describedby="describedBy"
					@input="onInput">
				<output class="af-colour__value" :for="domId">{{ answered ? stringValue : t('webforms', 'Not chosen') }}</output>
				<button v-if="answered" type="button" class="af-slider__clear" @click="emit('update:modelValue', '')">
					{{ t('webforms', 'Clear') }}
				</button>
			</div>
			<div v-else class="af-input" :class="{ 'af-input--affixed': hasAffix }">
				<span v-if="field.type === 'number' && field.prefix" class="af-affix" aria-hidden="true">{{ field.prefix }}</span>
				<input :id="domId"
					:type="inputType"
					:value="stringValue"
					:placeholder="field.placeholder"
					:minlength="field.type === 'text' ? field.minLength : undefined"
					:maxlength="field.type === 'text' ? field.maxLength : undefined"
					:min="field.type === 'number' ? numberLimit(field.min) : (isTemporal ? textLimit(field.min) : undefined)"
					:max="field.type === 'number' ? numberLimit(field.max) : (isTemporal ? textLimit(field.max) : undefined)"
					:step="field.type === 'number' ? (field.step ?? 'any') : undefined"
					:aria-required="required || undefined"
					:aria-invalid="error ? true : undefined"
					:aria-describedby="describedBy"
					@input="onInput">
				<span v-if="field.type === 'number' && field.suffix" class="af-affix" aria-hidden="true">{{ field.suffix }}</span>
			</div>
		</template>

		<p v-if="field.description && !isGroup && !isCompound" :id="domId + '-desc'" class="af-description">
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

.af-options {
	display: grid;
	grid-template-columns: minmax(0, 1fr);
	column-gap: 16px;
}

.af-input--affixed {
	display: flex;
	align-items: center;
	gap: 8px;
}

.af-input--affixed input {
	flex: 1;
	min-width: 0;
}

.af-affix {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
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

.af-image {
	margin-bottom: 16px;
}

.af-image img {
	max-width: 100%;
	height: auto;
	vertical-align: middle;
	border-radius: var(--border-radius-small, 4px);
}

.af-consent {
	align-items: flex-start;
}

.af-consent input {
	/* Level with the first line of the text. */
	margin-top: calc((1.5em - 14px) / 2);
}

.af-consent__text :deep(p) {
	margin: 0;
}

.af-rating {
	display: flex;
	flex-wrap: wrap;
	gap: 2px;
}

.af-rating__star {
	display: flex;
	color: var(--color-border-maxcontrast, var(--color-border-dark));
	cursor: pointer;
	border-radius: var(--border-radius-small, 4px);
}

.af-rating__star svg {
	width: 32px;
	height: 32px;
	fill: currentColor;
}

.af-rating__star--on {
	color: var(--color-primary-element);
}

.af-scale__row {
	display: flex;
	gap: 4px;
}

.af-scale__step {
	display: flex;
	flex: 1 1 0;
	align-items: center;
	justify-content: center;
	min-width: 0;
	max-width: 56px;
	min-height: var(--default-clickable-area, 34px);
	border: 2px solid var(--color-border-maxcontrast, var(--color-border-dark));
	border-radius: var(--border-radius-element, 8px);
	cursor: pointer;
	font-weight: 600;
}

.af-scale__step--on {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.af-rating__star:has(input:focus-visible),
.af-scale__step:has(input:focus-visible) {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.af-scale__ends {
	display: flex;
	justify-content: space-between;
	gap: 16px;
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.af-range {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 16px;
}

.af-range label {
	display: block;
	flex: 1 1 10rem;
}

.af-range__label {
	display: block;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.af-slider,
.af-colour {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 16px;
}

.af-field .af-slider__range {
	flex: 1 1 12rem;
	width: auto;
	min-height: var(--default-clickable-area, 34px);
	accent-color: var(--color-primary-element);
}

.af-field .af-slider__range--unset {
	opacity: 0.55;
}

.af-slider__value,
.af-colour__value {
	min-width: 4rem;
	font-variant-numeric: tabular-nums;
}

.af-field .af-colour input[type='color'] {
	width: 64px;
	height: var(--default-clickable-area, 34px);
	padding: 2px;
	cursor: pointer;
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
