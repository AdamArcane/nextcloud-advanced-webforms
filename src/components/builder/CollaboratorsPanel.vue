<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Collaborator, Sharee } from '../../api/forms.ts'

import { t } from '@nextcloud/l10n'
import SettingsCard from './SettingsCard.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { findSharees, listCollaborators, shareForm, unshareForm } from '../../api/forms.ts'
import { ApiError } from '../../api/http.ts'
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'

/**
 * Who a form is shared with (docs/SCHEMA.md sections 22.1 and 22.4). Shown to the owner only. An editor
 * builds and publishes the form and works with its responses; a reviewer only reads and sorts responses.
 */
const props = defineProps<{
	formId: number
	/** The form has an upload that is kept in the owner's Files, which stays with the owner's account. */
	hasFilesUploads?: boolean
}>()

type Role = 'editor' | 'reviewer'

const loading = ref(true)
const loadFailed = ref(false)
const owner = ref<{ id: string, displayName: string } | null>(null)
const shares = ref<Collaborator[]>([])
const problem = ref<string | null>(null)
const busy = ref(false)

const query = ref('')
const results = ref<Sharee[]>([])
const searching = ref(false)
const newRole = ref<Role>('editor')
let timer: ReturnType<typeof setTimeout> | undefined
// Answers can come back out of order, so only the latest search is shown.
let searchRun = 0

async function load(): Promise<void> {
	loading.value = true
	loadFailed.value = false
	try {
		const loaded = await listCollaborators(props.formId)
		owner.value = loaded.owner
		shares.value = loaded.shares
	} catch {
		loadFailed.value = true
	} finally {
		loading.value = false
	}
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))
watch(() => props.formId, () => {
	query.value = ''
	results.value = []
	void load()
})

watch(query, (value) => {
	clearTimeout(timer)
	if (value.trim() === '') {
		results.value = []
		searching.value = false
		return
	}
	searching.value = true
	timer = setTimeout(() => void search(value.trim()), 250)
})

async function search(text: string): Promise<void> {
	const run = ++searchRun
	try {
		const found = await findSharees(props.formId, text)
		if (run === searchRun) {
			results.value = found
		}
	} catch {
		if (run === searchRun) {
			results.value = []
		}
	} finally {
		if (run === searchRun) {
			searching.value = false
		}
	}
}

function failure(error: unknown): string {
	const code = error instanceof ApiError ? (error.errors[0]?.code ?? error.code) : ''
	if (code === 'share_target_invalid') {
		return t('webforms', 'That account or group no longer exists.')
	}
	if (code === 'share_is_owner') {
		return t('webforms', 'The owner already has full access.')
	}
	return t('webforms', 'Could not change who the form is shared with. Please try again.')
}

async function add(sharee: Sharee): Promise<void> {
	busy.value = true
	problem.value = null
	try {
		const saved = await shareForm(props.formId, sharee.type, sharee.with, newRole.value)
		shares.value = [...shares.value.filter((share) => share.id !== saved.id), saved]
		query.value = ''
		results.value = []
	} catch (error) {
		problem.value = failure(error)
	} finally {
		busy.value = false
	}
}

async function changeRole(share: Collaborator, role: string): Promise<void> {
	if (role !== 'editor' && role !== 'reviewer') {
		return
	}
	busy.value = true
	problem.value = null
	try {
		const saved = await shareForm(props.formId, share.type, share.with, role)
		shares.value = shares.value.map((one) => (one.id === saved.id ? saved : one))
	} catch (error) {
		problem.value = failure(error)
		// The menu shows what is saved, not what was tried.
		shares.value = [...shares.value]
	} finally {
		busy.value = false
	}
}

async function remove(share: Collaborator): Promise<void> {
	busy.value = true
	problem.value = null
	try {
		await unshareForm(props.formId, share.id)
		shares.value = shares.value.filter((one) => one.id !== share.id)
	} catch (error) {
		problem.value = failure(error)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<SettingsCard :title="t('webforms', 'Collaborators')"
		:description="t('webforms', 'An editor builds and publishes the form and works with its responses. A reviewer can only read responses, sort them with statuses and notes, and download them.')">
		<div class="af-collab">

		<NcLoadingIcon v-if="loading" :size="24" />
		<NcNoteCard v-else-if="loadFailed" type="error">
			{{ t('webforms', 'Could not load who the form is shared with.') }}
			<NcButton variant="tertiary" @click="load">
				{{ t('webforms', 'Try again') }}
			</NcButton>
		</NcNoteCard>
		<template v-else>
			<ul class="af-collab__list" :aria-label="t('webforms', 'People and groups with access')">
				<li v-if="owner" class="af-collab__row">
					<Icon :path="icons.user" />
					<span class="af-collab__name">{{ owner.displayName }}</span>
					<span class="af-collab__role">{{ t('webforms', 'Owner') }}</span>
				</li>
				<li v-for="share in shares" :key="share.id" class="af-collab__row">
					<Icon :path="share.type === 'group' ? icons.group : icons.user" />
					<span class="af-collab__name">
						{{ share.displayName }}
						<span v-if="share.type === 'group'" class="af-collab__tag">{{ t('webforms', 'Group') }}</span>
					</span>
					<select class="af-collab__select"
						:value="share.role"
						:disabled="busy"
						:aria-label="t('webforms', 'Role of {name}', { name: share.displayName })"
						@change="changeRole(share, ($event.target as HTMLSelectElement).value)">
						<option value="editor">
							{{ t('webforms', 'Editor') }}
						</option>
						<option value="reviewer">
							{{ t('webforms', 'Reviewer') }}
						</option>
					</select>
					<NcButton variant="tertiary"
						:disabled="busy"
						:aria-label="t('webforms', 'Remove {name}', { name: share.displayName })"
						@click="remove(share)">
						<template #icon>
							<Icon :path="icons.delete" />
						</template>
					</NcButton>
				</li>
			</ul>

			<div class="af-collab__add">
				<NcTextField v-model="query"
					:label="t('webforms', 'Add a person or group')"
					:placeholder="t('webforms', 'Search by name')"
					autocomplete="off" />
				<select v-model="newRole" class="af-collab__select" :aria-label="t('webforms', 'Role for the new collaborator')">
					<option value="editor">
						{{ t('webforms', 'Editor') }}
					</option>
					<option value="reviewer">
						{{ t('webforms', 'Reviewer') }}
					</option>
				</select>
			</div>
			<NcLoadingIcon v-if="searching" :size="20" />
			<ul v-else-if="results.length > 0" class="af-collab__results" :aria-label="t('webforms', 'Search results')">
				<li v-for="sharee in results" :key="sharee.type + ':' + sharee.with">
					<button type="button" class="af-collab__result" :disabled="busy" @click="add(sharee)">
						<Icon :path="sharee.type === 'group' ? icons.group : icons.user" />
						<span>{{ sharee.label }}</span>
						<span v-if="sharee.type === 'group'" class="af-collab__tag">{{ t('webforms', 'Group') }}</span>
					</button>
				</li>
			</ul>
			<p v-else-if="query.trim() !== ''" class="af-collab__hint">
				{{ t('webforms', 'Nobody found. People and groups you may share with depend on this server\'s sharing settings.') }}
			</p>

			<NcNoteCard v-if="problem" type="error">
				{{ problem }}
			</NcNoteCard>
			<NcNoteCard v-if="hasFilesUploads" type="info">
				{{ t('webforms', 'Files uploaded to this form are kept in your own Files. Collaborators open them through the form. If your account is deleted they go with it, unless the folder is a group folder.') }}
			</NcNoteCard>
			<p class="af-collab__hint">
				{{ t('webforms', 'Notes on responses can be read by everyone this form is shared with.') }}
			</p>
		</template>
		</div>
	</SettingsCard>
</template>

<style scoped>
.af-collab {
	display: flex;
	flex-direction: column;
	align-items: stretch;
	gap: calc(var(--default-grid-baseline) * 2);
}

.af-collab__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.af-collab__list,
.af-collab__results {
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-collab__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.af-collab__name {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.af-collab__role {
	color: var(--color-text-maxcontrast);
}

.af-collab__tag {
	margin-inline-start: 6px;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.af-collab__select {
	flex: none;
	width: auto;
	margin: 0;
}

.af-collab__add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.af-collab__add > :deep(.input-field) {
	flex: 1 1 auto;
}

.af-collab__result {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	padding: 8px;
	border: 0;
	border-radius: var(--border-radius-large);
	background: transparent;
	color: inherit;
	text-align: start;
	cursor: pointer;
}

.af-collab__result:hover,
.af-collab__result:focus-visible {
	background: var(--color-background-hover);
}
</style>
