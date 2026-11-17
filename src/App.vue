<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { OwnerForm } from './types.ts'

import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { createForm, formExportUrl, importForm, listForms, setFormArchived } from './api/forms.ts'
import { ApiError } from './api/http.ts'
import { DEFAULT_PREFERENCES, fetchUserPolicy, fetchUserPreferences, OPEN_POLICY, saveUserPreferences } from './api/settings.ts'
import type { UserPolicy, UserPreferences } from './api/settings.ts'
import { describeError } from './builder/errors.ts'
import Icon from './components/common/Icon.vue'
import { icons } from './icons.ts'
import BuilderView from './components/builder/BuilderView.vue'
import EntriesView from './components/entries/EntriesView.vue'
import SettingsDialog from './components/SettingsDialog.vue'
import { statusText } from './availability.ts'
import { FORM_STATUS_PUBLISHED } from './types.ts'

const forms = ref<OwnerForm[]>([])
const loading = ref(true)
const loadFailed = ref(false)
const creating = ref(false)
const createFailed = ref(false)
const policy = ref<UserPolicy>(OPEN_POLICY)
const preferences = ref<UserPreferences>({ ...DEFAULT_PREFERENCES })
const preferencesLoaded = ref(false)
const settingsOpen = ref(false)
const importing = ref(false)
const importError = ref<string | null>(null)
const importInput = ref<HTMLInputElement | null>(null)
const route = ref(readHash())
const selectedId = computed(() => route.value.id)
const view = computed(() => route.value.view)
// Archived forms stay out of the main list and have their own section below it.
const activeForms = computed(() => forms.value.filter((form) => form.archivedAt == null))
const archivedForms = computed(() => forms.value.filter((form) => form.archivedAt != null))
const archiveOpen = ref(false)
const archiveError = ref(false)
const selectedTitle = computed(() => forms.value.find((form) => form.id === selectedId.value)?.title ?? '')

// The selected form and screen live in the URL hash, so a reload, the back button and a shared link all work.
type View = 'edit' | 'settings' | 'responses'

/** `#/f/<id>`, `#/f/<id>/settings`, `#/f/<id>/responses`, or one response at `#/f/<id>/responses/<entryId>`. */
function readHash(): { id: number | null, view: View, entryId: number | null } {
	const match = /^#\/f\/(\d+)(?:\/(settings|responses(?:\/(\d+))?))?$/.exec(window.location.hash)
	if (!match) {
		return { id: null, view: 'edit', entryId: null }
	}
	const view: View = match[2] === undefined ? 'edit' : match[2] === 'settings' ? 'settings' : 'responses'
	return { id: Number(match[1]), view, entryId: match[3] === undefined ? null : Number(match[3]) }
}

function onHashChange(): void {
	route.value = readHash()
}

function open(id: number | null): void {
	window.location.hash = id === null ? '' : '/f/' + id
}

/**
 * Where a form in the list opens. A published form with nothing left to publish has no editing
 * waiting, so it opens on its responses, unless the user switched that off in the settings.
 * A typed or shared `#/f/<id>` address still means the editor.
 */
function formHash(form: OwnerForm): string {
	const settled = preferences.value.openPublishedOnResponses && form.status === FORM_STATUS_PUBLISHED && form.hasUnpublishedChanges === false
	return '#/f/' + form.id + (settled ? '/responses' : '')
}

function openForm(form: OwnerForm): void {
	window.location.hash = formHash(form).slice(1)
}

async function load(): Promise<void> {
	try {
		forms.value = await listForms()
		loadFailed.value = false
	} catch {
		loadFailed.value = true
	} finally {
		loading.value = false
	}
}

onMounted(() => {
	window.addEventListener('hashchange', onHashChange)
	void fetchUserPolicy().then((loadedPolicy) => {
		policy.value = loadedPolicy
	})
	void fetchUserPreferences().then((loadedPreferences) => {
		preferences.value = loadedPreferences
		preferencesLoaded.value = true
	})
	void load()
})

onBeforeUnmount(() => window.removeEventListener('hashchange', onHashChange))

/**
 * Change some preferences: shown at once, saved in the background, and put back if the server says no.
 * Only the preferences that were changed are touched, so two quick changes cannot undo each other.
 */
async function savePreference(patch: Partial<UserPreferences>): Promise<void> {
	const keys = Object.keys(patch) as (keyof UserPreferences)[]
	const pick = (from: UserPreferences): Partial<UserPreferences> => Object.fromEntries(keys.map((key) => [key, from[key]]))
	const previous = pick(preferences.value)
	preferences.value = { ...preferences.value, ...patch }
	try {
		preferences.value = { ...preferences.value, ...pick(await saveUserPreferences(patch)) }
	} catch {
		preferences.value = { ...preferences.value, ...previous }
		showError(t('webforms', 'Could not save the setting.'))
	}
}

async function create(): Promise<void> {
	creating.value = true
	createFailed.value = false
	try {
		const form = await createForm(t('webforms', 'Untitled form'))
		forms.value = [form, ...forms.value]
		open(form.id)
	} catch {
		createFailed.value = true
	} finally {
		creating.value = false
	}
}

// A form file is a few hundred KB at most (the server caps a definition at 512 KB), so anything bigger is not one.
const MAX_IMPORT_BYTES = 1024 * 1024

function pickImportFile(): void {
	importError.value = null
	importInput.value?.click()
}

async function onImportFile(event: Event): Promise<void> {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0]
	// Cleared so picking the same file again still fires a change.
	input.value = ''
	if (!file) {
		return
	}
	importing.value = true
	importError.value = null
	try {
		if (file.size > MAX_IMPORT_BYTES) {
			throw new ApiError(0, 'too_large')
		}
		let pkg: unknown
		try {
			pkg = JSON.parse(await file.text())
		} catch {
			throw new ApiError(0, 'import_not_a_form')
		}
		const { form } = await importForm(pkg)
		forms.value = [form, ...forms.value]
		open(form.id)
	} catch (error) {
		const first = error instanceof ApiError ? (error.errors[0]?.code ?? error.code) : 'failed'
		importError.value = first === 'failed' || first === 'network'
			? t('webforms', 'Could not import the form.')
			: describeError(first)
	} finally {
		importing.value = false
	}
}

/** The owner looked at a form's responses, so none of them is new any more. */
function onResponsesSeen(id: number): void {
	forms.value = forms.value.map((form) => (form.id === id ? { ...form, newCount: 0 } : form))
}

/** Keep the list in step with edits made in the builder (title, status). */
function onChanged(form: OwnerForm): void {
	forms.value = forms.value.map((existing) => (existing.id === form.id ? { ...existing, ...form } : existing))
}

function onDeleted(): void {
	forms.value = forms.value.filter((form) => form.id !== selectedId.value)
	open(null)
}

async function toggleArchived(form: OwnerForm): Promise<void> {
	archiveError.value = false
	try {
		const next = await setFormArchived(form.id, form.archivedAt == null)
		onChanged(next)
	} catch {
		archiveError.value = true
	}
}

/** A published form whose draft has moved on. A form that was never published is simply a draft. */
function hasNewerDraft(form: OwnerForm): boolean {
	return form.latestVersionId !== null && form.hasUnpublishedChanges === true
}

</script>

<template>
	<NcContent app-name="webforms">
		<NcAppNavigation :aria-label="t('webforms', 'Forms')">
			<template #list>
				<NcAppNavigationNew :text="t('webforms', 'New form')"
					:disabled="creating || !policy.canCreate"
					@click="create">
					<template #icon>
						<NcLoadingIcon v-if="creating" :size="20" />
						<span v-else aria-hidden="true" class="af-new-plus">+</span>
					</template>
				</NcAppNavigationNew>
				<NcAppNavigationList>
					<NcAppNavigationItem v-for="form in activeForms"
						:key="form.id"
						:name="form.title"
						:active="form.id === selectedId"
						:href="formHash(form)"
						:details="statusText(form)"
						:class="{ 'af-nav--newer': hasNewerDraft(form) }"
						:title="hasNewerDraft(form) ? t('webforms', '{title} (unpublished changes)', { title: form.title }) : undefined"
						:aria-description="hasNewerDraft(form) ? t('webforms', 'The draft has changes that are not published yet') : undefined"
						@click="openForm(form)">
						<template v-if="(form.newCount ?? 0) > 0" #counter>
							<NcCounterBubble :count="form.newCount ?? 0"
								type="highlighted"
								:title="n('webforms', '%n new response', '%n new responses', form.newCount ?? 0)"
								:aria-label="n('webforms', '%n new response', '%n new responses', form.newCount ?? 0)" />
						</template>
						<template #actions>
							<NcActionLink :href="formExportUrl(form.id, 'draft')"
								:download="''"
								close-after-click>
								<template #icon>
									<Icon :path="icons.download" :inline="false" />
								</template>
								{{ t('webforms', 'Export draft') }}
							</NcActionLink>
							<NcActionLink v-if="form.latestVersionId !== null"
								:href="formExportUrl(form.id, 'published')"
								:download="''"
								close-after-click>
								<template #icon>
									<Icon :path="icons.download" :inline="false" />
								</template>
								{{ t('webforms', 'Export published version') }}
							</NcActionLink>
							<NcActionButton close-after-click @click="toggleArchived(form)">
								<template #icon>
									<Icon :path="icons.archive" :inline="false" />
								</template>
								{{ t('webforms', 'Archive') }}
							</NcActionButton>
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationList>
				<NcAppNavigationList v-if="archivedForms.length > 0">
					<NcAppNavigationItem v-model:open="archiveOpen"
						:name="t('webforms', 'Archived')"
						:allow-collapse="true"
						:counter-number="archivedForms.length"
						:force-display-actions="false">
						<template #icon>
							<Icon :path="icons.archive" />
						</template>
						<NcAppNavigationItem v-for="form in archivedForms"
							:key="form.id"
							:name="form.title"
							:active="form.id === selectedId"
							:href="'#/f/' + form.id"
							@click="open(form.id)">
							<template #actions>
								<NcActionButton close-after-click @click="toggleArchived(form)">
									<template #icon>
										<Icon :path="icons.restore" :inline="false" />
									</template>
									{{ t('webforms', 'Restore') }}
								</NcActionButton>
								<NcActionLink :href="formExportUrl(form.id, 'draft')"
									:download="''"
									close-after-click>
									<template #icon>
										<Icon :path="icons.download" :inline="false" />
									</template>
									{{ t('webforms', 'Export draft') }}
								</NcActionLink>
							</template>
						</NcAppNavigationItem>
					</NcAppNavigationItem>
				</NcAppNavigationList>
				<NcAppNavigationItem :name="t('webforms', 'Import form')"
					:disabled="importing || !policy.canCreate"
					@click="pickImportFile">
					<template #icon>
						<NcLoadingIcon v-if="importing" :size="20" />
						<Icon v-else :path="icons.import" />
					</template>
				</NcAppNavigationItem>
				<input ref="importInput"
					type="file"
					accept=".json,application/json"
					class="af-hidden-input"
					tabindex="-1"
					aria-hidden="true"
					@change="onImportFile">
				<NcNoteCard v-if="importError" type="error">
					{{ importError }}
				</NcNoteCard>
				<NcNoteCard v-if="loadFailed" type="error">
					{{ t('webforms', 'Could not load your forms.') }}
					<NcButton variant="tertiary" @click="load">
						{{ t('webforms', 'Try again') }}
					</NcButton>
				</NcNoteCard>
				<NcNoteCard v-if="archiveError" type="error">
					{{ t('webforms', 'Could not change the archive state. Try again.') }}
				</NcNoteCard>
				<NcNoteCard v-if="!policy.canCreate" type="info">
					{{ t('webforms', 'You cannot create forms on this server. Ask an administrator to add you to a group that can.') }}
				</NcNoteCard>
				<NcNoteCard v-if="createFailed" type="error">
					{{ t('webforms', 'Could not create the form.') }}
				</NcNoteCard>
			</template>
			<template #footer>
				<ul class="app-navigation-entry__settings">
					<NcAppNavigationItem :name="t('webforms', 'Settings')" @click="settingsOpen = true">
						<template #icon>
							<Icon :path="icons.settings" />
						</template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>

		<SettingsDialog v-model:open="settingsOpen"
			:preferences="preferences"
			:loaded="preferencesLoaded"
			@change="savePreference" />

		<template v-if="selectedId !== null && view === 'responses'">
			<!-- Waits for the preferences, so the responses do not open one way and switch to the other. -->
			<EntriesView v-if="preferencesLoaded"
				:key="'responses-' + selectedId"
				:form-id="selectedId"
				:title="selectedTitle"
				:entry-id="route.entryId"
				:statuses="policy.statuses"
				:responses-view="preferences.responsesView"
				:responses-summary="preferences.responsesSummary"
				@preference="savePreference"
				@seen="onResponsesSeen(selectedId)" />
		</template>

		<BuilderView v-else-if="selectedId !== null"
			:key="selectedId"
			:form-id="selectedId"
			:view="view === 'settings' ? 'settings' : 'edit'"
			@changed="onChanged"
			@deleted="onDeleted" />

		<NcAppContent v-else>
			<div class="af-home">
				<NcLoadingIcon v-if="loading" :size="44" />
				<NcEmptyContent v-else-if="forms.length === 0"
					:name="t('webforms', 'Create your first form')"
					:description="t('webforms', 'Build forms with conditional fields and flexible layouts. Responses stay on your server.')">
					<template #action>
						<NcButton variant="primary" :disabled="creating" @click="create">
							{{ t('webforms', 'New form') }}
						</NcButton>
					</template>
				</NcEmptyContent>
				<NcEmptyContent v-else
					:name="t('webforms', 'Select a form')"
					:description="t('webforms', 'Choose a form from the list to edit it, or create a new one.')" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
/* A dot straight after the title, so it does not move when the row's menu appears on hover. */
.af-nav--newer :deep(.app-navigation-entry__name)::after {
	content: '';
	display: inline-block;
	width: 8px;
	height: 8px;
	margin-inline-start: 6px;
	border-radius: 50%;
	background: var(--color-warning, #eca700);
	vertical-align: middle;
}

.af-home {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}

.af-hidden-input {
	display: none;
}

.af-new-plus {
	font-size: 22px;
	line-height: 1;
}
</style>
