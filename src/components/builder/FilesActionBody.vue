<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { FilesAction } from '../../types.ts'

import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed } from 'vue'
import { cleanResponsePath } from '../../builder/actions.ts'

const props = defineProps<{
	action: FilesAction
}>()

const emit = defineEmits<{
	/** Changes to merge into the action. */
	patch: [changes: Partial<FilesAction>]
}>()

const problem = computed(() => cleanResponsePath(props.action.path) === null
	? t('webforms', 'Enter a file path in your Files that ends in .csv, like Webforms/Responses.csv.')
	: '')

/** The path as typed, with slashes at either end dropped, which is how the server stores it. */
function setPath(value: string | number): void {
	const typed = String(value)
	emit('patch', { path: cleanResponsePath(typed) ?? typed })
}

/** Picks the folder only: the file keeps its name, so the same file is not chosen by accident. */
async function pickFolder(): Promise<void> {
	const current = props.action.path.replace(/^\/+/, '')
	const slash = current.lastIndexOf('/')
	const name = (slash >= 0 ? current.slice(slash + 1) : current) || 'Responses.csv'
	let chosen: string | null = null
	const picker = getFilePickerBuilder(t('webforms', 'Choose a folder for the CSV file'))
		.setMultiSelect(false)
		.setMimeTypeFilter(['httpd/unix-directory'])
		.allowDirectories(true)
		.startAt('/' + (slash >= 0 ? current.slice(0, slash) : ''))
		.setButtonFactory((nodes, path) => [{
			label: nodes.length > 0 ? t('webforms', 'Choose {name}', { name: nodes[0].basename }) : t('webforms', 'Choose this folder'),
			variant: 'primary',
			callback: (picked) => {
				chosen = picked[0]?.path ?? path
			},
		}])
		.build()
	try {
		await picker.pickNodes()
	} catch {
		return
	}
	if (chosen === null) {
		return
	}
	const folder = (chosen as string).replace(/^\/+|\/+$/g, '')
	setPath(folder === '' ? name : folder + '/' + name)
}
</script>

<template>
	<div class="af-files">
		<NcTextField :model-value="action.path"
			:label="t('webforms', 'CSV file in your Files')"
			:placeholder="t('webforms', 'Webforms/Responses.csv')"
			:error="problem !== ''"
			:helper-text="problem"
			@update:model-value="setPath" />
		<div>
			<NcButton variant="secondary" @click="pickFolder">
				{{ t('webforms', 'Choose folder') }}
			</NcButton>
		</div>
		<p class="af-files__hint">
			{{ t('webforms', 'Each response is added as a line, with the same columns as the CSV download. The file is created when the first response arrives. If it already exists, its own header row is kept: answers go under the matching heading, and new questions are added as columns at the end.') }}
		</p>
		<p class="af-files__hint">
			{{ t('webforms', 'The file is written as you, so you must be able to change it. Deleting a response does not remove its line.') }}
		</p>
	</div>
</template>

<style scoped>
.af-files {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.af-files__hint {
	color: var(--color-text-maxcontrast);
}
</style>
