<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Folder } from '../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref, watch } from 'vue'
import { createFolder, renameFolder } from '../api/folders.ts'
import { ApiError } from '../api/http.ts'
import { describeError } from '../builder/errors.ts'

const props = defineProps<{
	open: boolean
	/** The folder to rename, or null to make a new one. */
	folder: Folder | null
}>()

const emit = defineEmits<{
	'update:open': [open: boolean]
	saved: [folder: Folder]
}>()

// The same limit as the server (FolderName).
const NAME_MAX = 100

const name = ref('')
const working = ref(false)
const error = ref<string | null>(null)

watch(() => props.open, (open) => {
	if (!open) {
		return
	}
	name.value = props.folder?.name ?? ''
	error.value = null
	working.value = false
}, { immediate: true })

const canSave = computed(() => !working.value && name.value.trim() !== '')

async function save(): Promise<void> {
	if (!canSave.value) {
		return
	}
	working.value = true
	error.value = null
	try {
		const saved = props.folder === null
			? await createFolder(name.value)
			: await renameFolder(props.folder.id, name.value)
		emit('saved', saved)
		emit('update:open', false)
	} catch (caught) {
		const code = caught instanceof ApiError ? caught.errors[0]?.code : undefined
		error.value = code ? describeError(code) : t('webforms', 'Could not save the folder.')
	} finally {
		working.value = false
	}
}
</script>

<template>
	<NcDialog :open="open"
		:name="folder === null ? t('webforms', 'New folder') : t('webforms', 'Rename folder')"
		size="small"
		@update:open="(value: boolean) => emit('update:open', value)">
		<div class="af-folder-dialog">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcTextField v-model="name"
				:label="t('webforms', 'Folder name')"
				:maxlength="NAME_MAX"
				@keydown.enter.prevent="save" />
		</div>
		<template #actions>
			<NcButton variant="tertiary" :disabled="working" @click="emit('update:open', false)">
				{{ t('webforms', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSave" @click="save">
				{{ folder === null ? t('webforms', 'Create folder') : t('webforms', 'Rename') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.af-folder-dialog {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding-bottom: 12px;
}
</style>
