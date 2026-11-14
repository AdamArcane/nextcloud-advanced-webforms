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
import { visibleLayout } from '../../layout/visibleLayout.ts'
import { evaluateRules } from '../../rules/engine.ts'
import { isInputType } from '../../rules/fieldTypes.ts'
import FieldInput from './FieldInput.vue'
import { messageFor } from './messages.ts'

const props = defineProps<{
	form: PublicForm
	/** Disables submitting, used by the builder preview. */
	preview?: boolean
}>()

const emit = defineEmits<{
	/** The fill token is no longer accepted; the parent should fetch a fresh one. */
	refreshToken: []
}>()

const definition = computed(() => props.form.definition)
const fields = computed(() => definition.value.fields)

function initialValue(field: FormField): unknown {
	const fallback = field.defaultValue
	switch (field.type) {
		case 'checkbox':
			return fallback === true
		case 'checkboxes':
			return Array.isArray(fallback) ? [...fallback] : []
		default:
			return typeof fallback === 'string' || typeof fallback === 'number' ? String(fallback) : ''
	}
}

const values = reactive<Record<string, unknown>>({})
for (const [id, field] of Object.entries(fields.value)) {
	if (isInputType(field.type)) {
		values[id] = initialValue(field)
	}
}

const honeypot = ref('')
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
const rows = computed(() => visibleLayout(definition.value.pages, visible.value))

const submitLabel = computed(() => definition.value.settings?.submitLabel || t('advancedforms', 'Send'))

function isEmpty(field: FormField, value: unknown): boolean {
	if (field.type === 'checkbox') {
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

async function onSubmit() {
	if (props.preview || submitting.value) {
		return
	}
	clearErrors()

	const submitted: Record<string, unknown> = {}
	for (const id of visible.value) {
		const field = fields.value[id]
		if (!isInputType(field.type)) {
			continue
		}
		if (required.value.has(id) && isEmpty(field, values[id])) {
			fieldErrors[id] = messageFor('required')
		}
		submitted[id] = values[id]
	}
	if (Object.keys(fieldErrors).length > 0) {
		await focusFirstError()
		return
	}

	submitting.value = true
	try {
		const result = await submitPublicForm(props.form.token, {
			versionId: props.form.versionId,
			values: submitted,
			fillToken: props.form.fillToken,
			hp: honeypot.value,
		})
		done.value = { message: result.message ?? t('advancedforms', 'Thank you. Your response has been sent.') }
		if (result.redirect?.startsWith('https://')) {
			window.location.assign(result.redirect)
		}
	} catch (error) {
		await showError(error)
	} finally {
		submitting.value = false
	}
}

async function showError(error: unknown) {
	if (!(error instanceof ApiError)) {
		formError.value = t('advancedforms', 'Something went wrong. Please try again.')
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
			formError.value = t('advancedforms', 'Too many attempts. Please wait a moment and try again.')
			return
		case 413:
			formError.value = t('advancedforms', 'Your answers are too large to send.')
			return
		case 410:
			formError.value = error.serverMessage ?? t('advancedforms', 'This form is no longer accepting responses.')
			return
		case 401:
			formError.value = t('advancedforms', 'Please log in to submit this form.')
			return
		default:
			formError.value = t('advancedforms', 'Something went wrong. Please try again.')
	}
}
</script>

<template>
	<NcNoteCard v-if="done" type="success" role="status">
		{{ done.message }}
	</NcNoteCard>

	<form v-else class="af-form" novalidate @submit.prevent="onSubmit">
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
					:label-position="renderedLabelPosition(fields[cell.field], definition.settings)" />
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

		<NcNoteCard v-if="formError" type="error" role="alert">
			{{ formError }}
		</NcNoteCard>

		<NcButton type="submit" variant="primary" :disabled="submitting || preview">
			{{ submitLabel }}
		</NcButton>
	</form>
</template>

<style scoped>
.af-form {
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

.af-hp {
	position: absolute;
	left: -10000px;
	width: 1px;
	height: 1px;
	overflow: hidden;
}
</style>
