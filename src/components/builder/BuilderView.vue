<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Move } from '../../builder/definition.ts'
import type { DefinitionError, DraftDefinition, FormField, FormSettings, OwnerForm } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ApiError } from '../../api/http.ts'
import { deleteForm, getForm, publicFormUrl, publishForm, saveDraft } from '../../api/forms.ts'
import { addField, locateField, moveField, removeField, setSpan, updateField } from '../../builder/definition.ts'
import { describeError, groupErrors } from '../../builder/errors.ts'
import { renameField } from '../../builder/fieldId.ts'
import type { Rule } from '../../rules/types.ts'
import { FORM_STATUS_PUBLISHED } from '../../types.ts'
import ActionsPanel from './ActionsPanel.vue'
import FormNav from '../FormNav.vue'
import BuilderCanvas from './BuilderCanvas.vue'
import FieldProperties from './FieldProperties.vue'
import FormProperties from './FormProperties.vue'
import LogicPanel from './LogicPanel.vue'
import PreviewDialog from './PreviewDialog.vue'
import SegmentedControl from './SegmentedControl.vue'

const props = defineProps<{
	formId: number
}>()

const emit = defineEmits<{
	/** The server's view of the form changed (title, status), so the list can refresh. */
	changed: [form: OwnerForm]
	deleted: []
}>()

type SaveState = 'saved' | 'dirty' | 'saving' | 'error'

const form = ref<OwnerForm | null>(null)
const draft = ref<DraftDefinition | null>(null)
const loadError = ref(false)
const saveState = ref<SaveState>('saved')
const errors = ref<DefinitionError[]>([])
const publishing = ref(false)
const publishFailed = ref(false)

const selectedId = ref<string | null>(null)
const sidebarOpen = ref(true)
const tab = ref<'field' | 'logic' | 'actions' | 'form'>('form')
const canvasWidth = ref<'desktop' | 'mobile'>('desktop')
const mobile = computed(() => canvasWidth.value === 'mobile')
const previewOpen = ref(false)
const confirmRemoveField = ref(false)
const confirmDeleteForm = ref(false)

// ---- Loading -------------------------------------------------------------

function normalize(definition: DraftDefinition): DraftDefinition {
	return {
		...definition,
		settings: definition.settings ?? {},
		fields: definition.fields ?? {},
		pages: definition.pages?.length ? definition.pages : [{ id: 'p_1', title: null, rows: [] }],
		rules: definition.rules ?? [],
		actions: definition.actions ?? [],
	}
}

onMounted(async () => {
	try {
		const loaded = await getForm(props.formId)
		form.value = loaded
		draft.value = normalize(loaded.draft as DraftDefinition)
	} catch {
		loadError.value = true
	}
	window.addEventListener('beforeunload', warnIfUnsaved)
})

onBeforeUnmount(() => {
	window.removeEventListener('beforeunload', warnIfUnsaved)
	// Leaving the form with edits pending: save them now rather than lose them.
	if (saveState.value === 'dirty') {
		void flush()
	}
})

function warnIfUnsaved(event: BeforeUnloadEvent): void {
	if (saveState.value === 'dirty' || saveState.value === 'saving') {
		event.preventDefault()
	}
}

// ---- Autosave ------------------------------------------------------------
// Saves are serialized: while one is in flight, later edits only mark the draft
// dirty and are sent when it returns, so an old response can never overwrite a
// newer edit. `revision` counts edits; a save only marks the draft clean if no
// edit arrived while it was running.

const SAVE_DELAY_MS = 700
let timer: ReturnType<typeof setTimeout> | undefined
let revision = 0
let inFlight = false

function edit(next: DraftDefinition): void {
	draft.value = next
	revision++
	saveState.value = 'dirty'
	clearTimeout(timer)
	timer = setTimeout(() => void flush(), SAVE_DELAY_MS)
}

async function flush(): Promise<void> {
	clearTimeout(timer)
	if (inFlight || draft.value === null || saveState.value === 'saved') {
		return
	}
	inFlight = true
	saveState.value = 'saving'
	const sentRevision = revision
	try {
		const result = await saveDraft(props.formId, draft.value)
		form.value = result.form
		errors.value = result.errors
		emit('changed', result.form)
		saveState.value = sentRevision === revision ? 'saved' : 'dirty'
	} catch {
		saveState.value = 'error'
	} finally {
		inFlight = false
	}
	if (saveState.value === 'dirty') {
		await flush()
	}
}

const saveLabel = computed(() => {
	switch (saveState.value) {
		case 'saving': return t('advancedforms', 'Saving…')
		case 'dirty': return t('advancedforms', 'Unsaved changes')
		case 'error': return t('advancedforms', 'Could not save')
		default: return t('advancedforms', 'Saved')
	}
})

// ---- Publishing ----------------------------------------------------------

const published = computed(() => form.value?.status === FORM_STATUS_PUBLISHED)
const shareUrl = computed(() => (form.value?.publicToken ? publicFormUrl(form.value.publicToken) : null))
const hasChanges = computed(() => form.value?.hasUnpublishedChanges !== false)
const publishLabel = computed(() => (form.value?.latestVersionId == null
	? t('advancedforms', 'Publish')
	: t('advancedforms', 'Publish changes')))
const canPublish = computed(() => !publishing.value && saveState.value !== 'error' && (hasChanges.value || !published.value))

async function publish(): Promise<void> {
	publishing.value = true
	publishFailed.value = false
	try {
		// Publish exactly what is on screen, not the last autosave.
		if (saveState.value === 'dirty') {
			await flush()
		}
		if (saveState.value === 'error') {
			return
		}
		form.value = await publishForm(props.formId)
		errors.value = []
		emit('changed', form.value)
	} catch (error) {
		if (error instanceof ApiError && error.status === 422) {
			errors.value = error.errors as unknown as DefinitionError[]
		}
		publishFailed.value = true
	} finally {
		publishing.value = false
	}
}

// ---- Editing -------------------------------------------------------------

const errorGroups = computed(() => groupErrors(errors.value))
const formErrors = computed(() => errorGroups.value.get('') ?? [])
const selectedField = computed(() => (selectedId.value && draft.value ? draft.value.fields[selectedId.value] : undefined))
const selectedCodes = computed(() => (selectedId.value ? (errorGroups.value.get(selectedId.value) ?? []).map((e) => e.code) : []))
const sidebarName = computed(() => {
	if (tab.value === 'actions') {
		return t('advancedforms', 'After submitting')
	}
	if ((tab.value === 'field' || tab.value === 'logic') && selectedField.value) {
		return selectedField.value.label || selectedField.value.text || t('advancedforms', 'Field')
	}
	return t('advancedforms', 'Form settings')
})
const sidebarSubname = computed(() => {
	if (tab.value === 'actions') {
		return t('advancedforms', 'Messages, emails and webhooks')
	}
	return tab.value === 'form' ? t('advancedforms', 'Applies to the whole form') : undefined
})

function select(fieldId: string): void {
	selectedId.value = fieldId
	tab.value = 'field'
	sidebarOpen.value = true
}

function onAdd(type: string, rowIndex: number): void {
	if (!draft.value) {
		return
	}
	const result = addField(draft.value, type, { kind: 'row', index: rowIndex })
	edit(result.definition)
	select(result.fieldId)
}

function onAddBeside(type: string): void {
	if (!draft.value || !selectedId.value) {
		return
	}
	const at = locateField(draft.value, selectedId.value)
	if (!at) {
		return
	}
	const result = addField(draft.value, type, { kind: 'join', rowId: at.row.id })
	edit(result.definition)
	select(result.fieldId)
}

function onUpdate(patch: Partial<FormField>): void {
	if (draft.value && selectedId.value) {
		edit(updateField(draft.value, selectedId.value, patch))
	}
}

function onSpan(span: number): void {
	if (draft.value && selectedId.value) {
		edit(setSpan(draft.value, selectedId.value, span))
	}
}

function onMove(move: Move): void {
	if (draft.value && selectedId.value) {
		edit(moveField(draft.value, selectedId.value, move))
	}
}

function onRename(newId: string): void {
	if (!draft.value || !selectedId.value) {
		return
	}
	const renamed = renameField(draft.value, selectedId.value, newId, form.value?.retiredIds ?? [])
	if (renamed !== draft.value) {
		edit(renamed)
		selectedId.value = newId
	}
}

function onRemoveConfirmed(): void {
	confirmRemoveField.value = false
	if (draft.value && selectedId.value) {
		edit(removeField(draft.value, selectedId.value))
		selectedId.value = null
		tab.value = 'form'
	}
}

function onRules(rules: Rule[]): void {
	if (draft.value) {
		edit({ ...draft.value, rules })
	}
}

function onTitle(title: string): void {
	if (draft.value) {
		edit({ ...draft.value, title })
	}
}

function onDescription(description: string): void {
	if (draft.value) {
		edit({ ...draft.value, description: description === '' ? null : description })
	}
}

function onSettings(patch: Partial<FormSettings>): void {
	if (!draft.value) {
		return
	}
	const settings: Record<string, unknown> = { ...draft.value.settings, ...patch }
	for (const key of Object.keys(settings)) {
		if (settings[key] === undefined) {
			delete settings[key]
		}
	}
	edit({ ...draft.value, settings: settings as FormSettings })
}

async function onDeleteConfirmed(): Promise<void> {
	confirmDeleteForm.value = false
	try {
		await deleteForm(props.formId)
		emit('deleted')
	} catch {
		saveState.value = 'error'
	}
}

function openSettings(): void {
	tab.value = 'form'
	sidebarOpen.value = true
}
</script>

<template>
	<NcAppContent class="af-builder">
		<div v-if="loadError" class="af-builder__center">
			<NcEmptyContent :name="t('advancedforms', 'Form not found')"
				:description="t('advancedforms', 'It may have been deleted, or you may not have access to it.')" />
		</div>
		<div v-else-if="!draft || !form" class="af-builder__center">
			<NcLoadingIcon :size="44" />
		</div>

		<template v-else>
			<header class="af-bar">
				<div class="af-bar__title">
					<h2 class="af-bar__name">
						{{ draft.title }}
					</h2>
					<span class="af-bar__status" :class="'af-bar__status--' + saveState" role="status">{{ saveLabel }}</span>
					<span v-if="published" class="af-bar__chip">
						{{ hasChanges ? t('advancedforms', 'Published · changes not live') : t('advancedforms', 'Published') }}
					</span>
					<span v-else class="af-bar__chip af-bar__chip--quiet">{{ t('advancedforms', 'Draft') }}</span>
				</div>
				<div class="af-bar__actions">
					<FormNav :form-id="formId" active="edit" />
					<SegmentedControl v-model="canvasWidth"
						class="af-bar__device"
						:group-label="t('advancedforms', 'Canvas width')"
						:options="[
							{ value: 'desktop', label: t('advancedforms', 'Desktop') },
							{ value: 'mobile', label: t('advancedforms', 'Mobile') },
						]" />
					<NcButton variant="tertiary" @click="openSettings">
						{{ t('advancedforms', 'Settings') }}
					</NcButton>
					<NcButton variant="secondary" @click="previewOpen = true">
						{{ t('advancedforms', 'Preview') }}
					</NcButton>
					<NcButton v-if="published && shareUrl" variant="tertiary" :href="shareUrl" target="_blank" rel="noopener">
						{{ t('advancedforms', 'Open form') }}
					</NcButton>
					<NcButton variant="primary" :disabled="!canPublish" @click="publish">
						{{ publishing ? t('advancedforms', 'Publishing…') : publishLabel }}
					</NcButton>
				</div>
			</header>

			<div class="af-builder__notes">
				<NcNoteCard v-if="saveState === 'error'" type="error">
					{{ t('advancedforms', 'Your latest changes could not be saved. Check your connection; they will be retried when you edit again.') }}
				</NcNoteCard>
				<NcNoteCard v-if="publishFailed && errors.length > 0" type="warning">
					{{ t('advancedforms', 'Fix the highlighted problems before publishing.') }}
					<template v-if="formErrors.length > 0">
						<br>{{ formErrors.map((error) => describeError(error.code)).join(' ') }}
					</template>
				</NcNoteCard>
				<NcNoteCard v-else-if="publishFailed" type="error">
					{{ t('advancedforms', 'The form could not be published.') }}
				</NcNoteCard>
			</div>

			<BuilderCanvas :definition="draft"
				:selected-id="selectedId"
				:errors="errors"
				:mobile="mobile"
				@select="select"
				@add="onAdd" />

			<PreviewDialog v-if="previewOpen" :definition="draft" @close="previewOpen = false" />

			<NcDialog v-if="confirmRemoveField"
				:name="t('advancedforms', 'Delete this field?')"
				:message="t('advancedforms', 'Rules that use this field will be updated or removed. Responses already received keep their answers.')"
				:buttons="[
					{ label: t('advancedforms', 'Cancel'), variant: 'tertiary', callback: () => { confirmRemoveField = false } },
					{ label: t('advancedforms', 'Delete'), variant: 'error', callback: onRemoveConfirmed },
				]"
				@closing="confirmRemoveField = false" />

			<NcDialog v-if="confirmDeleteForm"
				:name="t('advancedforms', 'Delete this form?')"
				:message="t('advancedforms', 'The form and all of its responses will be deleted. This cannot be undone.')"
				:buttons="[
					{ label: t('advancedforms', 'Cancel'), variant: 'tertiary', callback: () => { confirmDeleteForm = false } },
					{ label: t('advancedforms', 'Delete form'), variant: 'error', callback: onDeleteConfirmed },
				]"
				@closing="confirmDeleteForm = false" />
		</template>
	</NcAppContent>

	<NcAppSidebar v-if="draft && form"
		v-model:active="tab"
		v-model:open="sidebarOpen"
		:name="sidebarName"
		:subname="sidebarSubname">
		<NcAppSidebarTab id="field" :name="t('advancedforms', 'Field')" :order="1">
			<FieldProperties v-if="selectedId && selectedField"
				:key="selectedId"
				:definition="draft"
				:field-id="selectedId"
				:error-codes="selectedCodes"
				:retired-ids="form.retiredIds ?? []"
				:published="form.latestVersionId !== null"
				@update="onUpdate"
				@span="onSpan"
				@move="onMove"
				@add-beside="onAddBeside"
				@rename="onRename"
				@remove="confirmRemoveField = true" />
			<NcEmptyContent v-else
				class="af-builder__hint"
				:name="t('advancedforms', 'No field selected')"
				:description="t('advancedforms', 'Select a field on the canvas to edit it.')" />
		</NcAppSidebarTab>
		<NcAppSidebarTab id="logic" :name="t('advancedforms', 'Logic')" :order="2">
			<LogicPanel v-if="selectedId && selectedField"
				:key="selectedId"
				:definition="draft"
				:field-id="selectedId"
				:errors="errors"
				@rules="onRules"
				@select="select" />
			<NcEmptyContent v-else
				class="af-builder__hint"
				:name="t('advancedforms', 'No field selected')"
				:description="t('advancedforms', 'Select a field on the canvas to set up its rules.')" />
		</NcAppSidebarTab>
		<NcAppSidebarTab id="actions" :name="t('advancedforms', 'Actions')" :order="3">
			<ActionsPanel :definition="draft"
				:form-id="formId"
				:errors="errors"
				@change="edit" />
		</NcAppSidebarTab>
		<NcAppSidebarTab id="form" :name="t('advancedforms', 'Form')" :order="4">
			<FormProperties :definition="draft"
				:share-url="shareUrl"
				@title="onTitle"
				@description="onDescription"
				@settings="onSettings"
				@delete="confirmDeleteForm = true" />
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<style scoped>
.af-builder {
	display: flex;
	flex-direction: column;
}

.af-builder__center {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}

.af-bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
	/* Leave room for the sidebar toggle that Nextcloud pins to the top corner. */
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 2) calc(var(--default-clickable-area) + var(--default-grid-baseline) * 4);
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.af-bar__title {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 3);
	min-width: 0;
}

.af-bar__name {
	margin: 0;
	font-size: 20px;
	font-weight: bold;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 32ch;
}

.af-bar__status {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.af-bar__status--error {
	color: var(--color-error-text);
}

.af-bar__chip {
	padding: 0 calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-pill);
	background: var(--color-success);
	color: var(--color-success-text);
	font-size: 12px;
	line-height: 22px;
}

.af-bar__chip--quiet {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.af-bar__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.af-bar__device {
	min-width: 180px;
	margin-inline-end: var(--default-grid-baseline);
}

.af-builder__notes {
	max-width: 760px;
	width: 100%;
	margin: 0 auto;
	padding: 0 calc(var(--default-grid-baseline) * 4);
	box-sizing: border-box;
}

.af-builder__hint {
	margin-top: calc(var(--default-grid-baseline) * 8);
}
</style>
