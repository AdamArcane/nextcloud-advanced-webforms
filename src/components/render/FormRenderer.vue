<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { FormField, PublicForm } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, nextTick, reactive, ref } from 'vue'
import { ApiError, submitPublicForm } from '../../api/public.ts'
import { renderedLabelPosition } from '../../layout/labelPosition.ts'
import { progressColorStyle, progressLabel, progressStyle, submitAlign, submitButtonStyle } from '../../design.ts'
import { leaveTo } from '../../embedded.ts'
import { ownerImageUrl, publicImageUrl } from '../../images.ts'
import { visiblePages } from '../../layout/visibleLayout.ts'
import { evaluateRules } from '../../rules/engine.ts'
import { isBooleanType, isInputType } from '../../rules/fieldTypes.ts'
import CaptchaField from './CaptchaField.vue'
import FieldInput from './FieldInput.vue'
import { prefillValue } from '../../prefill.ts'
import { messageFor } from './messages.ts'
import { createUploadState } from './uploadState.ts'

const props = defineProps<{
	form: PublicForm
	/** Disables submitting, used by the builder preview. */
	preview?: boolean
	/** In the preview: check required fields as a respondent would. Off by default there. */
	validate?: boolean
}>()

const emit = defineEmits<{
	/** The fill token is no longer accepted; the parent should fetch a fresh one. */
	refreshToken: []
}>()

const definition = computed(() => props.form.definition)
const fields = computed(() => definition.value.fields)

// The address the form was opened with fills fields by their id (docs/SCHEMA.md 4.7), unless the form
// switches that off. Never in the builder preview: its address is the builder's own.
const params = props.preview || definition.value.settings?.prefill === false ? null : new URLSearchParams(window.location.search)

function initialValue(id: string, field: FormField): unknown {
	const given = params ? prefillValue(id, field, params) : undefined
	if (given !== undefined) {
		return Array.isArray(given) ? [...given] : given
	}
	const fallback = field.defaultValue
	switch (field.type) {
		case 'checkbox':
		case 'consent':
			return fallback === true
		case 'checkboxes':
			return Array.isArray(fallback) ? [...fallback] : []
		case 'file':
			return []
		default:
			return typeof fallback === 'string' || typeof fallback === 'number' ? String(fallback) : ''
	}
}

const values = reactive<Record<string, unknown>>({})
for (const [id, field] of Object.entries(fields.value)) {
	if (isInputType(field.type)) {
		values[id] = initialValue(id, field)
	}
}

const honeypot = ref('')
const captchaField = ref<InstanceType<typeof CaptchaField> | null>(null)
// The spam check belongs to the real page, not to the builder preview.
const captchaConfig = computed(() => (props.preview ? null : props.form.captcha ?? null))
// The files of upload fields. The form is not sent while one is still on its way.
const uploads = createUploadState(() => ({
	token: props.form.token,
	versionId: props.form.versionId,
	fillToken: props.form.fillToken,
	signedIn: props.form.signedIn === true,
	preview: props.preview === true,
	limit: props.form.uploadLimit,
}), values)
const fieldErrors = reactive<Record<string, string>>({})
const formError = ref<string | null>(null)
const submitting = ref(false)
const done = ref<{ message: string } | null>(null)

// Live visibility and required state. The server recomputes both; this is only feedback.
const state = computed(() => {
	try {
		return evaluateRules({ fields: fields.value, rules: definition.value.rules ?? [] }, values)
	} catch {
		// Rules were validated when the form was published, so this should not happen.
		// Fall back to showing everything with its static required flags.
		const ids = Object.keys(fields.value)
		return { visible: ids, required: ids.filter((id) => fields.value[id].required === true) }
	}
})
const visible = computed(() => new Set(state.value.visible))
const required = computed(() => new Set(state.value.required))
// A hidden field counts as visible to the rules, so its value is sent, but it never takes room on the page.
const shown = computed(() => new Set([...visible.value].filter((id) => fields.value[id]?.type !== 'hidden')))
const pages = computed(() => visiblePages(definition.value.pages, shown.value))
const submitStyle = computed(() => submitButtonStyle(definition.value.settings?.design))
const actionsAlign = computed(() => submitAlign(definition.value.settings?.design))
const pageIndex = ref(0)
// Hiding fields can remove pages, so never point past the end.
const currentIndex = computed(() => Math.min(pageIndex.value, Math.max(0, pages.value.length - 1)))
const currentPage = computed(() => pages.value[currentIndex.value])
const rows = computed(() => currentPage.value?.rows ?? [])
const isFirst = computed(() => currentIndex.value === 0)
const isLast = computed(() => currentIndex.value >= pages.value.length - 1)

// Progress through the pages (docs/SCHEMA.md 5.1). It counts the pages that can be reached right now,
// so the total follows the rules, and a form with one page shows nothing.
const progressText = ref<HTMLElement | null>(null)
const progressColor = computed(() => progressColorStyle(definition.value.settings?.design))
const progress = computed(() => {
	const total = pages.value.length
	const style = progressStyle(definition.value.settings?.design)
	const label = progressLabel(definition.value.settings?.design)
	if (total < 2 || (style === 'none' && label === 'none')) {
		return null
	}
	const step = currentIndex.value + 1
	const percent = Math.round((step / total) * 100)
	const stepText = t('webforms', 'Step {step} of {total}', { step, total })
	let text: string | null = stepText
	if (label === 'none') {
		text = null
	} else if (label === 'percent') {
		text = t('webforms', '{percent}%', { percent })
	} else if (label === 'title') {
		// A page without a name falls back to its step number.
		text = currentPage.value?.title?.trim() || stepText
	}
	return { style, step, total, percent, stepText, text }
})

/** The picture of an image block: the owner's own view in the preview, the form's route for respondents. */
function imageSrc(fieldId: string): string | null {
	const field = fields.value[fieldId]
	if (field?.type !== 'image' || typeof field.src !== 'string') {
		return null
	}
	return props.preview ? ownerImageUrl(field.src) : publicImageUrl(field.src, props.form.token, fieldId, props.form.versionId)
}

const submitLabel = computed(() => definition.value.settings?.submitLabel || t('webforms', 'Send'))

function isEmpty(field: FormField, value: unknown): boolean {
	if (isBooleanType(field.type)) {
		return value !== true
	}
	if (Array.isArray(value)) {
		return value.length === 0
	}
	return typeof value !== 'string' || value.trim() === ''
}

function clearErrors() {
	for (const key of Object.keys(fieldErrors)) {
		delete fieldErrors[key]
	}
	formError.value = null
}

async function focusFirstError() {
	await nextTick()
	const first = Object.keys(fieldErrors).find((id) => visible.value.has(id))
	if (first) {
		document.querySelector<HTMLElement>(`[data-field="${first}"] :is(input, select, textarea)`)?.focus()
	}
}

/** Mark empty required fields among `ids`, returning whether all were fine. */
function checkRequired(ids: Iterable<string>): boolean {
	let ok = true
	for (const id of ids) {
		const field = fields.value[id]
		if (visible.value.has(id) && isInputType(field.type) && required.value.has(id) && isEmpty(field, values[id])) {
			fieldErrors[id] = messageFor('required')
			ok = false
		}
	}
	return ok
}

function pageFieldIds(index: number): string[] {
	return (pages.value[index]?.rows ?? []).flatMap((row) => row.cells.map((cell) => cell.field))
}

/** Required fields are checked for respondents always, and in the preview only when `validate` is on. */
const checking = computed(() => !props.preview || props.validate === true)

async function goNext() {
	clearErrors()
	// The preview only checks when asked to, so you can look at pages without filling them in.
	if (checking.value && !checkRequired(pageFieldIds(currentIndex.value))) {
		await focusFirstError()
		return
	}
	pageIndex.value = currentIndex.value + 1
	await scrollToTop()
}

async function goBack() {
	clearErrors()
	pageIndex.value = Math.max(0, currentIndex.value - 1)
	await scrollToTop()
}

async function scrollToTop() {
	await nextTick()
	document.querySelector<HTMLElement>('.af-form')?.scrollIntoView?.({ block: 'start' })
	// Screen readers announce the new page, and the keyboard carries on from the top of it.
	progressText.value?.focus({ preventScroll: true })
}

async function onSubmit() {
	if (submitting.value) {
		return
	}
	// Paging works in the preview too; only the final submit is disabled there.
	if (!isLast.value) {
		await goNext()
		return
	}
	clearErrors()
	if (uploads.busy.value) {
		formError.value = t('webforms', 'Please wait until your files have finished uploading.')
		return
	}
	if (props.preview) {
		// Nothing is sent from the preview, but it can still show what respondents would be told.
		if (checking.value && !checkRequired(visible.value)) {
			const first = pages.value.findIndex((_, index) => pageFieldIds(index).some((id) => fieldErrors[id]))
			if (first !== -1) {
				pageIndex.value = first
			}
			await focusFirstError()
		}
		return
	}

	const submitted: Record<string, unknown> = {}
	for (const id of visible.value) {
		if (isInputType(fields.value[id].type)) {
			submitted[id] = values[id]
		}
	}
	if (!checkRequired(visible.value)) {
		// An error may sit on an earlier page: go to the first one that has one.
		const first = pages.value.findIndex((_, index) => pageFieldIds(index).some((id) => fieldErrors[id]))
		if (first !== -1) {
			pageIndex.value = first
		}
		await focusFirstError()
		return
	}

	let captcha: string | undefined
	if (captchaConfig.value) {
		captcha = (await captchaField.value?.response()) ?? undefined
		if (!captcha) {
			formError.value = t('webforms', 'Please complete the spam check before sending.')
			captchaField.value?.reset()
			return
		}
	}

	submitting.value = true
	try {
		const result = await submitPublicForm(props.form.token, {
			versionId: props.form.versionId,
			values: submitted,
			fillToken: props.form.fillToken,
			hp: honeypot.value,
			captcha,
		})
		done.value = { message: result.message ?? t('webforms', 'Thank you. Your response has been sent.') }
		if (result.redirect?.startsWith('https://')) {
			leaveTo(result.redirect)
		}
	} catch (error) {
		// An answer to the spam check works once, so the next attempt needs a new one.
		captchaField.value?.reset()
		await showError(error)
	} finally {
		submitting.value = false
	}
}

async function showError(error: unknown) {
	if (!(error instanceof ApiError)) {
		formError.value = t('webforms', 'Something went wrong. Please try again.')
		return
	}
	switch (error.status) {
		case 422:
			for (const item of error.errors) {
				if (item.field) {
					fieldErrors[item.field] = messageFor(item.code)
				} else {
					formError.value = messageFor(item.code)
					if (item.code === 'token_expired' || item.code === 'token_invalid') {
						emit('refreshToken')
					}
				}
			}
			await focusFirstError()
			return
		case 429:
			formError.value = t('webforms', 'Too many attempts. Please wait a moment and try again.')
			return
		case 413:
			formError.value = t('webforms', 'Your answers are too large to send.')
			return
		case 410:
			formError.value = error.serverMessage ?? t('webforms', 'This form is no longer accepting responses.')
			return
		case 401:
			formError.value = t('webforms', 'Please log in to submit this form.')
			return
		default:
			formError.value = t('webforms', 'Something went wrong. Please try again.')
	}
}
</script>

<template>
	<NcNoteCard v-if="done" type="success" role="status">
		{{ done.message }}
	</NcNoteCard>

	<form v-else class="af-form" novalidate @submit.prevent="onSubmit">
		<div v-if="progress" class="af-progress" :style="progressColor">
			<p v-if="progress.text"
				ref="progressText"
				class="af-progress__label"
				tabindex="-1">
				{{ progress.text }}
			</p>
			<div v-if="progress.style === 'bar'"
				class="af-progress__bar"
				role="progressbar"
				aria-valuemin="0"
				aria-valuemax="100"
				:aria-valuenow="progress.percent"
				:aria-valuetext="progress.stepText"
				:aria-label="t('webforms', 'Progress')">
				<div class="af-progress__fill" :style="{ width: progress.percent + '%' }" />
			</div>
			<ol v-else-if="progress.style === 'steps'" class="af-progress__steps" :aria-label="progress.stepText">
				<li v-for="n in progress.total"
					:key="n"
					class="af-progress__step"
					:class="{ 'af-progress__step--done': n < progress.step, 'af-progress__step--current': n === progress.step }"
					:aria-current="n === progress.step ? 'step' : undefined">
					<span class="af-progress__dot">{{ n }}</span>
				</li>
			</ol>
		</div>

		<div v-for="row in rows" :key="row.id" class="af-row">
			<div v-for="cell in row.cells"
				:key="cell.id"
				class="af-cell"
				:style="{ '--af-span': cell.span }">
				<FieldInput v-model="values[cell.field]"
					:field-id="cell.field"
					:field="fields[cell.field]"
					:required="required.has(cell.field)"
					:error="fieldErrors[cell.field] ?? null"
					:label-position="renderedLabelPosition(fields[cell.field], definition.settings)"
					:hide-required-indicator="definition.settings?.hideRequiredIndicator === true"
					:uploads="uploads"
					:signed-in="form.signedIn === true"
					:preview="preview === true"
					:upload-limit="form.uploadLimit"
					:image-src="imageSrc(cell.field)" />
			</div>
		</div>

		<!-- Honeypot: real people never see or fill this. -->
		<div class="af-hp" aria-hidden="true">
			<input v-model="honeypot"
				type="text"
				name="website"
				tabindex="-1"
				autocomplete="off">
		</div>

		<CaptchaField v-if="captchaConfig && isLast" ref="captchaField" :config="captchaConfig" />

		<NcNoteCard v-if="formError" type="error" role="alert">
			{{ formError }}
		</NcNoteCard>

		<div class="af-actions" :class="'af-actions--' + actionsAlign">
			<NcButton v-if="!isFirst" variant="tertiary" :disabled="submitting" @click="goBack">
				{{ t('webforms', 'Back') }}
			</NcButton>
			<NcButton v-if="!isLast" type="submit" variant="primary">
				{{ t('webforms', 'Next') }}
			</NcButton>
			<NcButton v-else
				type="submit"
				variant="primary"
				:style="submitStyle"
				:disabled="submitting">
				{{ submitLabel }}
			</NcButton>
		</div>
	</form>
</template>

<style scoped>
.af-form {
	width: 100%;
	container-type: inline-size;
}

.af-row {
	display: grid;
	grid-template-columns: repeat(12, minmax(0, 1fr));
	column-gap: 16px;
}

.af-cell {
	grid-column: span var(--af-span, 12);
	min-width: 0;
	/* Lets a field decide by its own width whether a side label fits. */
	container-type: inline-size;
}

/* Narrow containers stack every cell, in document order (docs/SCHEMA.md section 5). */
@container (max-width: 600px) {
	.af-cell {
		grid-column: 1 / -1;
	}
}

.af-actions {
	display: flex;
	gap: 8px;
}

.af-actions--center {
	justify-content: center;
}

.af-actions--right {
	justify-content: flex-end;
}

/* Justified: the buttons share the whole row, so a single submit button is as wide as the fields. */
.af-actions--justify > * {
	flex: 1 1 0;
}

.af-progress {
	margin: 0 0 20px;
}

.af-progress__label {
	margin: 0 0 6px;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

/* Focused by the form itself after Next and Back, never by the Tab key. */
.af-progress__label:focus {
	outline: none;
}

.af-progress__bar {
	height: 6px;
	border-radius: 3px;
	background: var(--color-background-dark);
	overflow: hidden;
}

.af-progress__fill {
	height: 100%;
	border-radius: 3px;
	background: var(--color-primary-element);
	transition: width 0.25s ease;
}

.af-progress__steps {
	display: flex;
	align-items: center;
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-progress__step {
	display: flex;
	align-items: center;
}

/* The line that joins a step to the one before it. */
.af-progress__step + .af-progress__step {
	flex: 1 1 0;
}

.af-progress__step + .af-progress__step::before {
	content: '';
	flex: 1 1 0;
	height: 2px;
	background: var(--color-border-dark);
}

.af-progress__step--done::before,
.af-progress__step--current::before {
	background: var(--color-primary-element) !important;
}

.af-progress__dot {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border: 2px solid var(--color-border-dark);
	border-radius: 50%;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	font-weight: 600;
	line-height: 1;
}

.af-progress__step--done .af-progress__dot,
.af-progress__step--current .af-progress__dot {
	border-color: var(--color-primary-element);
}

.af-progress__step--done .af-progress__dot {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.af-progress__step--current .af-progress__dot {
	color: var(--color-main-text);
}

/* Twenty numbered steps do not fit a phone: there they are plain dots. */
@container (max-width: 600px) {
	.af-progress__dot {
		width: 12px;
		height: 12px;
		font-size: 0;
	}
}

@media (prefers-reduced-motion: reduce) {
	.af-progress__fill {
		transition: none;
	}
}

.af-hp {
	position: absolute;
	left: -10000px;
	width: 1px;
	height: 1px;
	overflow: hidden;
}
</style>
