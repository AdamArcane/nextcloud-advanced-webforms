<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ResponseStatus } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import { statusColor } from '../../entries/statusColors.ts'
import { findStatus, statusLabel } from '../../entries/statuses.ts'

// The status of one response, as a small menu with the status's colour beside it
// (docs/SCHEMA.md section 20.2). The parent saves the choice.
const props = defineProps<{
	statuses: readonly ResponseStatus[]
	/** The id of the response's status, or null for none. */
	modelValue: string | null | undefined
	/** What a screen reader calls the menu, for example "Status of response 12". */
	label: string
	disabled?: boolean
}>()

const emit = defineEmits<{
	'update:modelValue': [status: string | null]
}>()

// A status the administrator has deleted reads as none.
const current = computed(() => findStatus(props.statuses, props.modelValue))

function onChange(event: Event): void {
	const value = (event.target as HTMLSelectElement).value
	emit('update:modelValue', value === '' ? null : value)
}
</script>

<template>
	<span class="af-status" :class="{ 'af-status--none': !current }" :style="current ? { '--af-status-color': statusColor(current.color) } : undefined">
		<span class="af-status__dot" aria-hidden="true" />
		<select class="af-status__select"
			:value="current?.id ?? ''"
			:aria-label="label"
			:disabled="disabled"
			@change="onChange">
			<option value="">
				{{ t('webforms', 'No status') }}
			</option>
			<option v-for="status in statuses" :key="status.id" :value="status.id">
				{{ statusLabel(status) }}
			</option>
		</select>
	</span>
</template>

<style scoped>
.af-status {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	max-width: 100%;
}

.af-status__dot {
	flex: 0 0 auto;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--af-status-color);
}

.af-status--none .af-status__dot {
	background: transparent;
	box-shadow: inset 0 0 0 1px var(--color-border-dark);
}

.af-status__select {
	min-width: 0;
	max-width: 14rem;
	min-height: 32px;
	margin: 0;
	padding-block: 2px;
	font-size: 13px;
	text-overflow: ellipsis;
}

.af-status--none .af-status__select {
	color: var(--color-text-maxcontrast);
}
</style>
