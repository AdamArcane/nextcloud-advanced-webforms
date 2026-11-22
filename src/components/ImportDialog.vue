<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { CoreFormSummary, CoreImportOutcome } from '../api/forms.ts'

import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, reactive, ref, watch } from 'vue'
import { fetchCoreForms, importCoreForm } from '../api/forms.ts'
import { ApiError } from '../api/http.ts'
import { describeError } from '../builder/errors.ts'
import { describeSkipped } from '../importReport.ts'

const props = defineProps<{
	open: boolean
}>()

const emit = defineEmits<{
	'update:open': [open: boolean]
	/** The person chose to import a file made by this app. The screen opens the file picker. */
	'pick-file': []
	/** Drafts were created. The screen reloads its list and opens the first. */
	imported: [formIds: number[]]
}>()

type Step = 'sources' | 'core'

interface Row {
	coreId: number
	title: string
	outcome: CoreImportOutcome | null
	error: string | null
}

const step = ref<Step>('sources')
const loading = ref(false)
const loadFailed = ref(false)
const available = ref(false)
const forms = ref<CoreFormSummary[]>([])
const picked = ref<number[]>([])
const withResponses = ref(false)
const working = ref(false)
const preview = ref(false)
const rows = ref<Row[]>([])

const anyResponses = computed(() => forms.value.some((form) => picked.value.includes(form.id) && form.responses > 0))
const created = computed(() => rows.value.flatMap((row) => (row.outcome?.formId != null ? [row.outcome.formId] : [])))
const done = computed(() => rows.value.length > 0 && !working.value)

// Looked up each time the dialog opens, so a form made in the Forms app a minute ago is there.
watch(() => props.open, async (open) => {
	if (!open) {
		return
	}
	step.value = 'sources'
	rows.value = []
	picked.value = []
	loadFailed.value = false
	loading.value = true
	try {
		const result = await fetchCoreForms()
		available.value = result.available && result.forms.length > 0
		forms.value = result.forms
	} catch {
		available.value = false
		loadFailed.value = true
	} finally {
		loading.value = false
	}
}, { immediate: true })

function close(): void {
	emit('update:open', false)
}

function fromFile(): void {
	close()
	emit('pick-file')
}

function togglePicked(id: number, on: boolean): void {
	picked.value = on ? [...picked.value, id] : picked.value.filter((existing) => existing !== id)
	rows.value = []
}

function toggleAll(on: boolean): void {
	picked.value = on ? forms.value.map((form) => form.id) : []
	rows.value = []
}

/** One form at a time, so a form with many responses cannot hold up the rest or run into a time limit. */
async function run(dryRun: boolean): Promise<void> {
	working.value = true
	preview.value = dryRun
	rows.value = []
	for (const form of forms.value.filter((candidate) => picked.value.includes(candidate.id))) {
		const row = reactive<Row>({ coreId: form.id, title: form.title, outcome: null, error: null })
		rows.value = [...rows.value, row]
		try {
			row.outcome = await importCoreForm(form.id, withResponses.value, dryRun)
		} catch (error) {
			const code = error instanceof ApiError ? (error.errors[0]?.code ?? error.code) : 'failed'
			row.error = code === 'failed' || code === 'network' || code === 'not_found'
				? t('webforms', 'Could not import this form.')
				: describeError(code)
		}
	}
	working.value = false
	if (!dryRun && created.value.length > 0) {
		emit('imported', created.value)
	}
}

function summary(outcome: CoreImportOutcome): string {
	const fields = n('webforms', '{count} field', '{count} fields', outcome.fields, { count: outcome.fields })
	if (!withResponses.value) {
		return preview.value
			? t('webforms', 'Would be a draft with {fields}.', { fields })
			: t('webforms', 'Imported as a draft with {fields}.', { fields })
	}
	const responses = preview.value
		? n('webforms', '{count} response', '{count} responses', outcome.responsesFound, { count: outcome.responsesFound })
		: t('webforms', '{done} of {all} responses', { done: outcome.responsesImported, all: outcome.responsesFound })
	return preview.value
		? t('webforms', 'Would be a closed form with {fields} and {responses}.', { fields, responses })
		: t('webforms', 'Imported as a closed form with {fields} and {responses}.', { fields, responses })
}
</script>

<template>
	<NcDialog :open="open"
		:name="step === 'sources' ? t('webforms', 'Import a form') : t('webforms', 'Import from Nextcloud Forms')"
		size="normal"
		@update:open="(value: boolean) => emit('update:open', value)">
		<ul v-if="step === 'sources'" class="af-import__sources">
			<li>
				<button type="button" class="af-import__source" @click="fromFile">
					<span class="af-import__title">{{ t('webforms', 'From a file') }}</span>
					<span class="af-import__hint">{{ t('webforms', 'A form you exported from Advanced Webforms, or one from a backup.') }}</span>
				</button>
			</li>
			<li>
				<button type="button"
					class="af-import__source"
					:disabled="loading || !available"
					@click="step = 'core'">
					<span class="af-import__title">{{ t('webforms', 'From Nextcloud Forms') }}</span>
					<span v-if="loading" class="af-import__hint">
						<NcLoadingIcon :size="16" /> {{ t('webforms', 'Looking for your forms…') }}
					</span>
					<span v-else-if="available" class="af-import__hint">
						{{ n('webforms', '{count} form of yours, with or without its responses.', '{count} forms of yours, with or without their responses.', forms.length, { count: forms.length }) }}
					</span>
					<span v-else-if="loadFailed" class="af-import__hint">
						{{ t('webforms', 'Could not look for your forms. Close this and try again.') }}
					</span>
					<span v-else class="af-import__hint">
						{{ t('webforms', 'You have no forms in the Forms app on this server.') }}
					</span>
				</button>
			</li>
		</ul>

		<div v-else class="af-import__core">
			<p class="af-import__hint">
				{{ t('webforms', 'Each form becomes a new draft. Nothing in the Forms app is changed, and nothing here is overwritten.') }}
			</p>

			<NcCheckboxRadioSwitch :model-value="picked.length === forms.length"
				:indeterminate="picked.length > 0 && picked.length < forms.length"
				:disabled="working"
				@update:model-value="toggleAll">
				{{ t('webforms', 'All forms') }}
			</NcCheckboxRadioSwitch>
			<ul class="af-import__forms">
				<li v-for="form in forms" :key="form.id">
					<NcCheckboxRadioSwitch :model-value="picked.includes(form.id)"
						:disabled="working"
						@update:model-value="(on: boolean) => togglePicked(form.id, on)">
						{{ form.title === '' ? t('webforms', 'Untitled form') : form.title }}
						<span class="af-import__hint">
							({{ n('webforms', '{count} response', '{count} responses', form.responses, { count: form.responses }) }})
						</span>
					</NcCheckboxRadioSwitch>
				</li>
			</ul>

			<NcCheckboxRadioSwitch v-model="withResponses" type="switch" :disabled="working" @update:model-value="rows = []">
				{{ t('webforms', 'Also import the responses') }}
			</NcCheckboxRadioSwitch>
			<NcNoteCard v-if="withResponses && anyResponses" type="info">
				{{ t('webforms', 'Responses need a published version to belong to, so these forms are published once and then closed. They take no new responses until you reopen them.') }}
			</NcNoteCard>

			<ul v-if="rows.length > 0" class="af-import__results" aria-live="polite">
				<li v-for="row in rows" :key="row.coreId" class="af-import__result">
					<strong>{{ row.title === '' ? t('webforms', 'Untitled form') : row.title }}</strong>
					<NcLoadingIcon v-if="row.outcome === null && row.error === null" :size="16" />
					<NcNoteCard v-else-if="row.error !== null" type="error">
						{{ row.error }}
					</NcNoteCard>
					<template v-else-if="row.outcome !== null">
						<span class="af-import__hint">{{ summary(row.outcome) }}</span>
						<NcNoteCard v-if="row.outcome.errors.length > 0" type="warning">
							{{ t('webforms', 'The draft has problems that you need to fix before you can publish it.') }}
						</NcNoteCard>
						<ul v-if="row.outcome.skipped.length > 0" class="af-import__skipped">
							<li v-for="(item, index) in row.outcome.skipped" :key="index">
								{{ describeSkipped(item) }}
							</li>
						</ul>
					</template>
				</li>
			</ul>
		</div>

		<template #actions>
			<template v-if="step === 'core'">
				<NcButton variant="tertiary" :disabled="working" @click="step = 'sources'">
					{{ t('webforms', 'Back') }}
				</NcButton>
				<NcButton variant="secondary" :disabled="working || picked.length === 0" @click="run(true)">
					{{ t('webforms', 'Preview') }}
				</NcButton>
				<NcButton v-if="!(done && !preview && created.length > 0)"
					variant="primary"
					:disabled="working || picked.length === 0"
					@click="run(false)">
					<template v-if="working" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('webforms', 'Import') }}
				</NcButton>
				<NcButton v-else variant="primary" @click="close">
					{{ t('webforms', 'Done') }}
				</NcButton>
			</template>
			<NcButton v-else variant="tertiary" @click="close">
				{{ t('webforms', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.af-import__sources,
.af-import__forms,
.af-import__results,
.af-import__skipped {
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-import__sources {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.af-import__source {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
	width: 100%;
	padding: 12px 16px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.af-import__source:hover:not(:disabled),
.af-import__source:focus-visible {
	background: var(--color-background-hover);
}

.af-import__source:disabled {
	cursor: default;
	opacity: 0.7;
}

.af-import__title {
	font-weight: bold;
}

.af-import__hint {
	color: var(--color-text-maxcontrast);
}

.af-import__core {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.af-import__forms {
	margin-inline-start: 16px;
}

.af-import__result {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px 0;
	border-top: 1px solid var(--color-border);
}

.af-import__skipped {
	list-style: disc;
	padding-inline-start: 20px;
	color: var(--color-text-maxcontrast);
}
</style>
