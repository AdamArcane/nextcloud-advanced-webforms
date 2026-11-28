<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Folder, OwnerForm } from '../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { computed } from 'vue'
import { sortFolders } from '../folders.ts'
import { icons } from '../icons.ts'
import Icon from './common/Icon.vue'

const props = defineProps<{
	form: OwnerForm
	folders: Folder[]
}>()

const emit = defineEmits<{
	close: []
	/** A folder's id, or null for none. */
	pick: [folderId: number | null]
	'new-folder': []
}>()

const current = computed(() => (props.folders.some((folder) => folder.id === props.form.folderId) ? props.form.folderId : null))

const choices = computed(() => [
	{ id: null, name: t('webforms', 'No folder') },
	...sortFolders(props.folders),
])
</script>

<template>
	<NcDialog :open="true"
		:name="t('webforms', 'Move to folder')"
		size="small"
		@update:open="(value: boolean) => { if (!value) emit('close') }">
		<p class="af-move__form">
			{{ form.title }}
		</p>
		<ul class="af-move" :aria-label="t('webforms', 'Folders')">
			<li v-for="choice in choices" :key="choice.id ?? 0">
				<NcButton variant="tertiary"
					wide
					alignment="start"
					:aria-current="choice.id === current ? 'true' : undefined"
					@click="emit('pick', choice.id)">
					<template #icon>
						<Icon :path="choice.id === current ? icons.check : icons.folder" />
					</template>
					{{ choice.name }}
				</NcButton>
			</li>
		</ul>
		<template #actions>
			<NcButton variant="tertiary" @click="emit('close')">
				{{ t('webforms', 'Cancel') }}
			</NcButton>
			<NcButton variant="secondary" @click="emit('new-folder')">
				<template #icon>
					<Icon :path="icons.newFolder" />
				</template>
				{{ t('webforms', 'New folder') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.af-move__form {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.af-move {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0 0 12px;
	list-style: none;
}
</style>
