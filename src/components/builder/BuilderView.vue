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
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ApiError } from '../../api/http.ts'
import { deleteForm, getForm, publicFormUrl, publishForm, saveDraft } from '../../api/forms.ts'
import { addField, addPage, dropField, duplicateField, dropRow, resizeField, type DropTarget, moveFieldToPage, locateField, movePage, removePage, setPageTitle, moveField, removeField, setSpan, updateField } from '../../builder/definition.ts'
import { describeError, groupErrors } from '../../builder/errors.ts'
import { renameField } from '../../builder/fieldId.ts'
import type { Rule } from '../../rules/types.ts'
import { FORM_STATUS_PUBLISHED } from '../../types.ts'
import ActionsPanel from './ActionsPanel.vue'
import FormBar from '../FormBar.vue'
import BuilderCanvas from './BuilderCanvas.vue'
import FieldProperties from './FieldProperties.vue'
import FormProperties from './FormProperties.vue'
import LogicPanel from './LogicPanel.vue'
import PreviewDialog from './PreviewDialog.vue'
import SegmentedControl from './SegmentedControl.vue'

const props = withDefaults(defineProps<{
	formId: number
	/** The canvas with its field sidebar, or the page for form-wide settings and actions. */
	view?: 'edit' | 'settings'
}>(), { view: 'edit' })

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
// On a narrow screen the sidebar covers the canvas, so it opens only once a field is picked.
const wide = typeof window.matchMedia === 'function' && window.matchMedia('(min-width: 1024px)').matches
const sidebarOpen = ref(wide)
const tab = ref<'field' | 'logic'>('field')
const settingsTab = ref<'general' | 'design' | 'sharing' | 'actions'>('general')
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
	if (selectedField.value) {
		return selectedField.value.label || selectedField.value.text || t('advancedforms', 'Field')
	}
	return t('advancedforms', 'Field')
})

function select(fieldId: string): void {
	selectedId.value = fieldId
	tab.value = 'field'
	sidebarOpen.value = true
}

function onAddPage(): void {
	if (draft.value) {
		edit(addPage(draft.value))
	}
}

function onPageTitle(pageId: string, title: string): void {
	if (draft.value) {
		edit(setPageTitle(draft.value, pageId, title))
	}
}

function onMovePage(pageId: string, direction: -1 | 1): void {
	if (draft.value) {
		edit(movePage(draft.value, pageId, direction))
	}
}

function onRemovePage(pageId: string): void {
	// removePage refuses a page that still has fields; the canvas disables the button too.
	if (draft.value) {
		edit(removePage(draft.value, pageId))
	}
}

function onAdd(type: string, rowIndex: number, pageIndex = 0): void {
	if (!draft.value) {
		return
	}
	const result = addField(draft.value, type, { kind: 'row', index: rowIndex, page: pageIndex })
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

function onDuplicate(): void {
	if (!draft.value || !selectedId.value) {
		return
	}
	const result = duplicateField(draft.value, selectedId.value, (label) => t('advancedforms', '{label} (copy)', { label }))
	if (result) {
		edit(result.definition)
		select(result.fieldId)
	}
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

function onDrop(fieldId: string, target: DropTarget): void {
	if (draft.value) {
		edit(dropField(draft.value, fieldId, target))
		select(fieldId)
	}
}

function onDropRow(rowId: string, page: number, index: number): void {
	if (draft.value) {
		edit(dropRow(draft.value, rowId, page, index))
	}
}

function onResize(fieldId: string, rawSpan: number): void {
	if (draft.value) {
		edit(resizeField(draft.value, fieldId, rawSpan))
	}
}

function onMove(move: Move): void {
	if (draft.value && selectedId.value) {
		edit(moveField(draft.value, selectedId.value, move))
	}
}

function onToPage(pageIndex: number): void {
	if (draft.value && selectedId.value) {
		edit(moveFieldToPage(draft.value, selectedId.value, pageIndex))
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
		if (!wide) {
			sidebarOpen.value = false
		}
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
			<FormBar :form-id="formId"
				:active="view"
				:title="draft.title"
				sidebar-toggle>
				<template #meta>
					<!-- An icon says it at a glance; the words stay for screen readers and as a tooltip. -->
					<span class="af-bar__status" :class="'af-bar__status--' + saveState" role="status" :title="saveLabel">
						<NcLoadingIcon v-if="saveState === 'saving'" :size="16" />
						<Icon v-else-if="saveState === 'error'" :path="icons.alert" :size="18" />
						<Icon v-else-if="saveState === 'dirty'" :path="icons.unsaved" :size="18" />
						<Icon v-else :path="icons.check" :size="18" />
						<span class="af-sr-only">{{ saveLabel }}</span>
					</span>
					<span v-if="published" class="af-bar__chip">
						{{ t('advancedforms', 'Published') }}
					</span>
					<span v-else class="af-bar__chip af-bar__chip--quiet">{{ t('advancedforms', 'Draft') }}</span>
				</template>
				<template #icons>
					<NcButton variant="tertiary"
						:aria-label="t('advancedforms', 'Preview')"
						:title="t('advancedforms', 'Preview')"
						@click="previewOpen = true">
						<template #icon>
							<Icon :path="icons.preview" />
						</template>
					</NcButton>
					<NcButton v-if="published && shareUrl"
						variant="tertiary"
						:href="shareUrl"
						target="_blank"
						rel="noopener"
						:aria-label="t('advancedforms', 'Open published form')"
						:title="t('advancedforms', 'Open published form')">
						<template #icon>
							<Icon :path="icons.openNew" />
						</template>
					</NcButton>
				</template>
				<template v-if="view === 'edit'" #center>
					<SegmentedControl v-model="canvasWidth"
						:group-label="t('advancedforms', 'Canvas width')"
						:options="[
							{ value: 'desktop', label: t('advancedforms', 'Desktop'), icon: icons.desktop },
							{ value: 'mobile', label: t('advancedforms', 'Mobile'), icon: icons.mobile },
						]"
						icon-only />
				</template>
				<template #end>
					<NcButton variant="primary" :disabled="!canPublish" @click="publish">
						{{ publishing ? t('advancedforms', 'Publishing…') : t('advancedforms', 'Publish') }}
					</NcButton>
				</template>
			</FormBar>

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

			<div v-if="view === 'settings'" class="af-settings">
				<div class="af-settings__inner">
					<SegmentedControl v-model="settingsTab"
						class="af-settings__tabs"
						collapse
						:group-label="t('advancedforms', 'Settings section')"
						:options="[
							{ value: 'general', label: t('advancedforms', 'General'), icon: icons.settings },
							{ value: 'design', label: t('advancedforms', 'Design'), icon: icons.design },
							{ value: 'sharing', label: t('advancedforms', 'Sharing'), icon: icons.share },
							{ value: 'actions', label: t('advancedforms', 'Actions'), icon: icons.tabActions },
						]" />
					<ActionsPanel v-if="settingsTab === 'actions'"
						:definition="draft"
						:form-id="formId"
						:errors="errors"
						:show-errors="publishFailed"
						@change="edit" />
					<FormProperties v-else
						:section="settingsTab"
						:definition="draft"
						:share-url="shareUrl"
						@title="onTitle"
						@description="onDescription"
						@settings="onSettings"
						@delete="confirmDeleteForm = true" />
				</div>
			</div>
			<BuilderCanvas v-else
				:definition="draft"
				:selected-id="selectedId"
				:errors="errors"
				:mobile="mobile"
				@select="select"
				@add="onAdd"
				@add-page="onAddPage"
				@drop-field="onDrop"
				@drop-row="onDropRow"
				@resize="onResize"
				@page-title="onPageTitle"
				@move-page="onMovePage"
				@remove-page="onRemovePage" />

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

	<NcAppSidebar v-if="draft && form && view === 'edit'"
		v-model:active="tab"
		v-model:open="sidebarOpen"
		:name="sidebarName">
		<NcAppSidebarTab id="field" :name="t('advancedforms', 'Field')" :order="1">
			<template #icon>
				<Icon :path="icons.tabField" />
			</template>
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
				@duplicate="onDuplicate"
				@to-page="onToPage"
				@rename="onRename"
				@remove="confirmRemoveField = true" />
			<NcEmptyContent v-else
				class="af-builder__hint"
				:name="t('advancedforms', 'No field selected')"
				:description="t('advancedforms', 'Select a field on the canvas to edit it.')" />
		</NcAppSidebarTab>
		<NcAppSidebarTab id="logic" :name="t('advancedforms', 'Logic')" :order="2">
			<template #icon>
				<Icon :path="icons.logic" />
			</template>
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

.af-bar__status {
	display: inline-flex;
	align-items: center;
	color: var(--color-text-maxcontrast);
}

.af-sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}

.af-bar__status--saved {
	color: var(--color-success-text);
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

/* On a phone the Published chip only takes room. */
@media (max-width: 700px) {
	.af-bar__chip {
		display: none;
	}
}

.af-settings {
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
}

.af-settings__inner {
	max-width: 760px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 8);
}

.af-settings__tabs {
	max-width: 420px;
	margin: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3) 0;
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
