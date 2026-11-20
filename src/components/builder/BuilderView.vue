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
import { isDestinationField } from '../../builder/destinations.ts'
import { fetchUserPolicy, OPEN_POLICY } from '../../api/settings.ts'
import type { UserPolicy } from '../../api/settings.ts'
import type { DraftConflict } from '../../api/forms.ts'
import { deleteForm, getForm, publicFormUrl, publishForm, saveDraft, setFormArchived } from '../../api/forms.ts'
import { addField, addPage, dropField, duplicateField, dropRow, resizeField, type DropTarget, moveFieldToPage, locateField, movePage, removePage, setPageTitle, moveField, removeField, setSpan, updateField } from '../../builder/definition.ts'
import { describeError, groupErrors, withoutUnstarted } from '../../builder/errors.ts'
import { followLabel, renameField } from '../../builder/fieldId.ts'
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

type SaveState = 'saved' | 'dirty' | 'saving' | 'error' | 'conflict'

const form = ref<OwnerForm | null>(null)
const policy = ref<UserPolicy>(OPEN_POLICY)
// Fields that were already in a published form when it was opened keep their ids (see followLabel).
let frozenIds = new Set<string>()
const draft = ref<DraftDefinition | null>(null)
const loadError = ref(false)
const saveState = ref<SaveState>('saved')
const errors = ref<DefinitionError[]>([])
const publishing = ref(false)
const archiveFailed = ref(false)
const publishFailed = ref(false)

// The owner may do everything. An editor may not change what reaches into the owner's Files or Tables
// (docs/SCHEMA.md sections 22.1 and 22.5); a form from an answer that carries no role belongs to the person.
const isOwner = computed(() => form.value?.role === undefined || form.value.role === 'owner')
const ownerOnlyNotice = ref(false)
// Set when someone else saved the draft first (docs/SCHEMA.md section 22.6). Saving stops until the person chooses.
const conflict = ref<DraftConflict | null>(null)
// The revision this copy came from or was last saved at.
let baseRevision = 1
const hasFilesUploads = computed(() => Object.values(draft.value?.fields ?? {}).some((field) => field.type === 'file' && field.storage !== 'app'))

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
	void fetchUserPolicy().then((loadedPolicy) => {
		policy.value = loadedPolicy
	})
	try {
		const loaded = await getForm(props.formId)
		form.value = loaded
		baseRevision = loaded.revision ?? 1
		draft.value = normalize(loaded.draft as DraftDefinition)
		frozenIds = loaded.latestVersionId !== null ? new Set(Object.keys(draft.value.fields)) : new Set()
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
	if (saveState.value === 'conflict') {
		return
	}
	saveState.value = 'dirty'
	clearTimeout(timer)
	timer = setTimeout(() => void flush(), SAVE_DELAY_MS)
}

async function flush(): Promise<void> {
	clearTimeout(timer)
	if (inFlight || draft.value === null || saveState.value === 'saved' || saveState.value === 'conflict') {
		return
	}
	inFlight = true
	saveState.value = 'saving'
	const sentRevision = revision
	try {
		const result = await saveDraft(props.formId, draft.value, baseRevision)
		baseRevision = result.form.revision ?? baseRevision + 1
		form.value = result.form
		errors.value = result.errors
		emit('changed', result.form)
		ownerOnlyNotice.value = false
		saveState.value = sentRevision === revision ? 'saved' : 'dirty'
	} catch (error) {
		if (error instanceof ApiError && error.status === 409 && error.code === 'draft_conflict') {
			conflict.value = error.data as unknown as DraftConflict
			saveState.value = 'conflict'
			return
		}
		saveState.value = 'error'
		// The server refuses an editor's change to what uses the owner's Files or Tables.
		ownerOnlyNotice.value = error instanceof ApiError && error.errors.some((one) => one.code === 'owner_only_destination')
	} finally {
		inFlight = false
	}
	if (saveState.value === 'dirty') {
		await flush()
	}
}

/** Replace the screen with the version that was saved first. The unsaved edits are lost. */
async function useTheirVersion(): Promise<void> {
	clearTimeout(timer)
	try {
		const loaded = await getForm(props.formId)
		form.value = loaded
		baseRevision = loaded.revision ?? conflict.value?.revision ?? baseRevision
		draft.value = normalize(loaded.draft as DraftDefinition)
		errors.value = []
		conflict.value = null
		saveState.value = 'saved'
		emit('changed', loaded)
	} catch {
		// The conflict stays on screen, so the person can try again.
	}
}

/** Save over the other version, by naming the stored revision as the one this copy started from. */
async function keepMine(): Promise<void> {
	if (!conflict.value) {
		return
	}
	baseRevision = conflict.value.revision
	conflict.value = null
	saveState.value = 'dirty'
	await flush()
}

/** A copy of the person's own draft, so choosing the other version loses nothing for good. */
function downloadMine(): void {
	if (!draft.value) {
		return
	}
	const link = document.createElement('a')
	link.href = URL.createObjectURL(new Blob([JSON.stringify(draft.value, null, 2)], { type: 'application/json' }))
	link.download = 'my-version.json'
	link.click()
	URL.revokeObjectURL(link.href)
}

const conflictWhen = computed(() => (conflict.value ? new Date(conflict.value.savedAt * 1000).toLocaleString() : ''))

const saveLabel = computed(() => {
	switch (saveState.value) {
		case 'conflict': return t('webforms', 'Not saved')
		case 'saving': return t('webforms', 'Saving…')
		case 'dirty': return t('webforms', 'Unsaved changes')
		case 'error': return t('webforms', 'Could not save')
		default: return t('webforms', 'Saved')
	}
})

// ---- Publishing ----------------------------------------------------------

const published = computed(() => form.value?.status === FORM_STATUS_PUBLISHED)
const archived = computed(() => form.value?.archivedAt != null)
const shareUrl = computed(() => {
	// The custom address when there is one: it is the one the owner hands out.
	const address = form.value?.slug || form.value?.publicToken
	return address ? publicFormUrl(address) : null
})

/** The form was handed to someone else: the person is an editor now, and the draft on screen is untouched. */
function onTransferred(saved: OwnerForm): void {
	if (!form.value) {
		return
	}
	form.value = { ...form.value, role: saved.role, ownerId: saved.ownerId, ownerName: saved.ownerName, canLeave: saved.canLeave, updatedAt: saved.updatedAt }
	emit('changed', form.value)
}

/** The sharing options are saved apart from the draft, so only they are taken from the answer. */
function onShare(saved: OwnerForm): void {
	if (!form.value) {
		return
	}
	form.value = { ...form.value, slug: saved.slug, embed: saved.embed, embedOrigins: saved.embedOrigins, updatedAt: saved.updatedAt }
	emit('changed', form.value)
}
const hasChanges = computed(() => form.value?.hasUnpublishedChanges !== false)
const canPublish = computed(() => !publishing.value && !archived.value && saveState.value !== 'error' && saveState.value !== 'conflict' && (hasChanges.value || !published.value))

async function publish(): Promise<void> {
	publishing.value = true
	publishFailed.value = false
	try {
		// Publish exactly what is on screen, not the last autosave.
		if (saveState.value === 'dirty') {
			await flush()
		}
		if (saveState.value === 'error' || saveState.value === 'conflict') {
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

// An empty new block is only reported once publishing has been tried.
const shownErrors = computed(() => (publishFailed.value ? errors.value : withoutUnstarted(errors.value, draft.value)))
const errorGroups = computed(() => groupErrors(shownErrors.value))
const formErrors = computed(() => errorGroups.value.get('') ?? [])
const selectedField = computed(() => (selectedId.value && draft.value ? draft.value.fields[selectedId.value] : undefined))
// Keyed by the layout cell, which stays put when the field's id changes as its label is typed.
// Keying by the id would rebuild the panel on every keystroke and drop focus.
const selectedKey = computed(() => (selectedId.value && draft.value ? (locateField(draft.value, selectedId.value)?.cell.id ?? selectedId.value) : ''))
const selectedCodes = computed(() => (selectedId.value ? (errorGroups.value.get(selectedId.value) ?? []).map((e) => e.code) : []))
const sidebarName = computed(() => {
	if (selectedField.value) {
		return selectedField.value.label || selectedField.value.text || t('webforms', 'Field')
	}
	return t('webforms', 'Field')
})

function select(fieldId: string): void {
	selectedId.value = fieldId
	tab.value = 'field'
	sidebarOpen.value = true
}

/** The hover toolbar acts on its own field, which becomes the selected one (the delete dialog uses the selection). */
function onToolbarRemove(fieldId: string): void {
	selectedId.value = fieldId
	askRemoveField()
}

/** Removing a field that uses the owner's Files is a change to where files go, so an editor is told instead. */
function askRemoveField(): void {
	if (!isOwner.value && selectedId.value !== null && isDestinationField(draft.value?.fields[selectedId.value])) {
		ownerOnlyNotice.value = true
		return
	}
	confirmRemoveField.value = true
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

/** A file field an editor adds is kept in the app: keeping it in the owner's Files would be the owner's to decide. */
function forRole(definition: DraftDefinition, fieldId: string): DraftDefinition {
	const field = definition.fields[fieldId]
	if (isOwner.value || !field || field.type !== 'file') {
		return definition
	}
	return { ...definition, fields: { ...definition.fields, [fieldId]: { ...field, storage: 'app' } } }
}

function onAdd(type: string, rowIndex: number, pageIndex = 0): void {
	if (!draft.value) {
		return
	}
	const result = addField(draft.value, type, { kind: 'row', index: rowIndex, page: pageIndex }, form.value?.retiredIds ?? [])
	edit(forRole(result.definition, result.fieldId))
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
	const result = addField(draft.value, type, { kind: 'join', rowId: at.row.id }, form.value?.retiredIds ?? [])
	edit(forRole(result.definition, result.fieldId))
	select(result.fieldId)
}

function onDuplicate(fieldId: string | null = selectedId.value): void {
	if (!draft.value || !fieldId) {
		return
	}
	const result = duplicateField(draft.value, fieldId, (label) => t('webforms', '{label} (copy)', { label }))
	if (result) {
		// The copy is "<label> (copy)", so its id follows that rather than staying random.
		const named = followLabel(result.definition, result.fieldId, undefined, form.value?.retiredIds ?? [], frozenIds)
		edit(named.definition)
		select(named.fieldId)
	}
}

function onUpdate(patch: Partial<FormField>): void {
	if (draft.value && selectedId.value) {
		const previousLabel = draft.value.fields[selectedId.value]?.label
		const updated = updateField(draft.value, selectedId.value, patch)
		// The id follows the label while the builder made it; a hand-picked id is left alone.
		const named = 'label' in patch
			? followLabel(updated, selectedId.value, previousLabel, form.value?.retiredIds ?? [], frozenIds)
			: { definition: updated, fieldId: selectedId.value }
		edit(named.definition)
		selectedId.value = named.fieldId
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
		// Chosen by hand: from now on the label no longer changes it.
		frozenIds.add(newId)
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

async function toggleArchived(): Promise<void> {
	if (!form.value) {
		return
	}
	try {
		// Keep the draft the server has up to date before the list changes around it.
		const next = await setFormArchived(props.formId, !archived.value)
		form.value = { ...form.value, archivedAt: next.archivedAt, updatedAt: next.updatedAt }
		emit('changed', form.value)
	} catch {
		archiveFailed.value = true
	}
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
			<NcEmptyContent :name="t('webforms', 'Form not found')"
				:description="t('webforms', 'It may have been deleted, or you may not have access to it.')" />
		</div>
		<div v-else-if="!draft || !form" class="af-builder__center">
			<NcLoadingIcon :size="44" />
		</div>

		<template v-else>
			<FormBar :form-id="formId"
				:active="view"
				:title="draft.title"
				sidebar-toggle
				renamable
				@rename="onTitle">
				<template #meta>
					<!-- An icon says it at a glance; the words stay for screen readers and as a tooltip. -->
					<span class="af-bar__status" :class="'af-bar__status--' + saveState" role="status" :title="saveLabel">
						<NcLoadingIcon v-if="saveState === 'saving'" :size="16" />
						<Icon v-else-if="saveState === 'error'" :path="icons.alert" :size="18" />
						<Icon v-else-if="saveState === 'dirty'" :path="icons.unsaved" :size="18" />
						<Icon v-else :path="icons.check" :size="18" />
						<span class="af-sr-only">{{ saveLabel }}</span>
					</span>
					<span v-if="archived" class="af-bar__chip af-bar__chip--quiet">{{ t('webforms', 'Archived') }}</span>
					<span v-else-if="published" class="af-bar__chip">
						{{ t('webforms', 'Published') }}
					</span>
					<span v-else class="af-bar__chip af-bar__chip--quiet">{{ t('webforms', 'Draft') }}</span>
				</template>
				<template #icons>
					<NcButton variant="tertiary"
						:aria-label="t('webforms', 'Preview')"
						:title="t('webforms', 'Preview')"
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
						:aria-label="t('webforms', 'Open published form')"
						:title="t('webforms', 'Open published form')">
						<template #icon>
							<Icon :path="icons.openNew" />
						</template>
					</NcButton>
				</template>
				<template v-if="view === 'edit'" #center>
					<SegmentedControl v-model="canvasWidth"
						:group-label="t('webforms', 'Canvas width')"
						:options="[
							{ value: 'desktop', label: t('webforms', 'Desktop'), icon: icons.desktop },
							{ value: 'mobile', label: t('webforms', 'Mobile'), icon: icons.mobile },
						]"
						icon-only />
				</template>
				<template #end>
					<NcButton variant="primary" :disabled="!canPublish" @click="publish">
						{{ publishing ? t('webforms', 'Publishing…') : t('webforms', 'Publish') }}
					</NcButton>
				</template>
			</FormBar>

			<div class="af-builder__notes">
				<NcNoteCard v-if="saveState === 'error'" type="error">
					{{ t('webforms', 'Your latest changes could not be saved. Check your connection; they will be retried when you edit again.') }}
				</NcNoteCard>
				<NcNoteCard v-if="archived" type="info">
					{{ t('webforms', 'This form is archived. It is not taking responses.') }}
					<NcButton variant="secondary" @click="toggleArchived">
						<template #icon>
							<Icon :path="icons.restore" />
						</template>
						{{ t('webforms', 'Restore form') }}
					</NcButton>
				</NcNoteCard>
				<NcNoteCard v-if="conflict" type="warning" data-test="draft-conflict">
					<p>
						{{ conflict.savedBy
							? t('webforms', '{name} changed this form at {when}. Your changes are not saved yet.', { name: conflict.savedBy, when: conflictWhen })
							: t('webforms', 'This form was changed at {when}, in another window or by someone else. Your changes are not saved yet.', { when: conflictWhen }) }}
					</p>
					<NcButton variant="primary" data-test="conflict-use-theirs" @click="useTheirVersion">
						{{ t('webforms', 'Use their version') }}
					</NcButton>
					<NcButton data-test="conflict-keep-mine" @click="keepMine">
						{{ t('webforms', 'Keep mine') }}
					</NcButton>
					<NcButton variant="tertiary" data-test="conflict-download" @click="downloadMine">
						{{ t('webforms', 'Download my version') }}
					</NcButton>
				</NcNoteCard>
				<NcNoteCard v-if="form?.destinationsPending" type="warning" data-test="destinations-pending">
					{{ isOwner
						? t('webforms', 'This form was closed when the previous owner\'s account was deleted, because the places it saves to in Files and Tables are not yet set up for you. Check them, then publish to open it again.')
						: t('webforms', 'This form is closed because the places it saves to in Files and Tables are not set up for its new owner yet. They need to check them and publish it again.') }}
				</NcNoteCard>
				<NcNoteCard v-if="ownerOnlyNotice" type="warning">
					{{ t('webforms', 'Only the owner of this form can change where files are saved or use Tables, because they use the owner\'s Files and Tables. Your other changes are kept on this screen. Ask the owner, or undo the change.') }}
				</NcNoteCard>
				<NcNoteCard v-if="archiveFailed" type="error">
					{{ t('webforms', 'Could not change the archive state. Try again.') }}
				</NcNoteCard>
				<NcNoteCard v-if="publishFailed && errors.length > 0" type="warning">
					{{ t('webforms', 'Fix the highlighted problems before publishing.') }}
					<template v-if="formErrors.length > 0">
						<br>{{ formErrors.map((error) => describeError(error.code)).join(' ') }}
					</template>
				</NcNoteCard>
				<NcNoteCard v-else-if="publishFailed" type="error">
					{{ t('webforms', 'The form could not be published.') }}
				</NcNoteCard>
			</div>

			<div v-if="view === 'settings'" class="af-settings">
				<div class="af-settings__inner">
					<SegmentedControl v-model="settingsTab"
						class="af-settings__tabs"
						collapse
						:group-label="t('webforms', 'Settings section')"
						:options="[
							{ value: 'general', label: t('webforms', 'General'), icon: icons.settings },
							{ value: 'design', label: t('webforms', 'Design'), icon: icons.design },
							{ value: 'sharing', label: t('webforms', 'Sharing'), icon: icons.share },
							{ value: 'actions', label: t('webforms', 'Actions'), icon: icons.tabActions },
						]" />
					<ActionsPanel v-if="settingsTab === 'actions'"
						:definition="draft"
						:form-id="formId"
						:errors="shownErrors"
						:show-errors="publishFailed"
						:allowed-types="policy.allowedActionTypes"
						:max-actions="policy.maxActions"
						:mail="policy.mail"
						:owner-only="!isOwner"
						@change="edit" />
					<FormProperties v-else
						:section="settingsTab"
						:definition="draft"
						:share-url="shareUrl"
						:form="form"
						:archived="archived"
						:is-owner="isOwner"
						:can-share="policy.canShare !== false"
						:has-files-uploads="hasFilesUploads"
						@share="onShare"
						@transferred="onTransferred"
						@archive="toggleArchived"
						@title="onTitle"
						@description="onDescription"
						@settings="onSettings"
						@delete="confirmDeleteForm = true" />
				</div>
			</div>
			<BuilderCanvas v-else
				:definition="draft"
				:selected-id="selectedId"
				:errors="shownErrors"
				:mobile="mobile"
				@select="select"
				@add="onAdd"
				@add-page="onAddPage"
				@drop-field="onDrop"
				@drop-row="onDropRow"
				@resize="onResize"
				@page-title="onPageTitle"
				@move-page="onMovePage"
				@remove-page="onRemovePage"
				@duplicate="onDuplicate"
				@remove="onToolbarRemove" />

			<PreviewDialog v-if="previewOpen" :definition="draft" @close="previewOpen = false" />

			<NcDialog v-if="confirmRemoveField"
				:name="t('webforms', 'Delete this field?')"
				:message="t('webforms', 'Rules that use this field will be updated or removed. Responses already received keep their answers.')"
				:buttons="[
					{ label: t('webforms', 'Cancel'), variant: 'tertiary', callback: () => { confirmRemoveField = false } },
					{ label: t('webforms', 'Delete'), variant: 'error', callback: onRemoveConfirmed },
				]"
				@closing="confirmRemoveField = false" />

			<NcDialog v-if="confirmDeleteForm"
				:name="t('webforms', 'Delete this form?')"
				:message="t('webforms', 'The form and all of its responses will be deleted. This cannot be undone.')"
				:buttons="[
					{ label: t('webforms', 'Cancel'), variant: 'tertiary', callback: () => { confirmDeleteForm = false } },
					{ label: t('webforms', 'Delete form'), variant: 'error', callback: onDeleteConfirmed },
				]"
				@closing="confirmDeleteForm = false" />
		</template>
	</NcAppContent>

	<NcAppSidebar v-if="draft && form && view === 'edit'"
		v-model:active="tab"
		v-model:open="sidebarOpen"
		:name="sidebarName">
		<NcAppSidebarTab id="field" :name="t('webforms', 'Field')" :order="1">
			<template #icon>
				<Icon :path="icons.tabField" />
			</template>
			<FieldProperties v-if="selectedId && selectedField"
				:key="selectedKey"
				:definition="draft"
				:field-id="selectedId"
				:error-codes="selectedCodes"
				:retired-ids="form.retiredIds ?? []"
				:published="form.latestVersionId !== null"
				:owner-only="!isOwner"
				@update="onUpdate"
				@span="onSpan"
				@move="onMove"
				@add-beside="onAddBeside"
				@duplicate="onDuplicate"
				@to-page="onToPage"
				@rename="onRename"
				@remove="askRemoveField" />
			<NcEmptyContent v-else
				class="af-builder__hint"
				:name="t('webforms', 'No field selected')"
				:description="t('webforms', 'Select a field on the canvas to edit it.')" />
		</NcAppSidebarTab>
		<NcAppSidebarTab id="logic" :name="t('webforms', 'Logic')" :order="2">
			<template #icon>
				<Icon :path="icons.logic" />
			</template>
			<LogicPanel v-if="selectedId && selectedField"
				:key="selectedKey"
				:definition="draft"
				:field-id="selectedId"
				:errors="shownErrors"
				@rules="onRules"
				@select="select" />
			<NcEmptyContent v-else
				class="af-builder__hint"
				:name="t('webforms', 'No field selected')"
				:description="t('webforms', 'Select a field on the canvas to set up its rules.')" />
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
