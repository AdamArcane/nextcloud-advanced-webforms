<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ResponseColumn } from '../../entries/columns.ts'
import type { EntrySort } from '../../entries/filter.ts'
import type { EntryRecord, VersionDefinition } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { cellText } from '../../entries/columns.ts'
import { formatTime } from '../../entries/format.ts'
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'
import ResponseCard from './ResponseCard.vue'

defineProps<{
	formId: number
	entries: EntryRecord[]
	versions: Record<string, VersionDefinition>
	columns: ResponseColumn[]
	expandedId: number | null
	sort: EntrySort
}>()

const emit = defineEmits<{
	toggle: [id: number]
	delete: [id: number]
	changed: []
	sort: [sort: EntrySort]
}>()

const EMPTY: VersionDefinition = { fields: {}, pages: [] }
</script>

<template>
	<div class="af-table-wrap">
		<table class="af-table">
			<thead>
				<tr>
					<th scope="col" class="af-table__id">
						#
					</th>
					<th scope="col">
						<button type="button"
							class="af-table__sort"
							:aria-label="sort === 'newest' ? t('webforms', 'Submitted, newest first. Show oldest first.') : t('webforms', 'Submitted, oldest first. Show newest first.')"
							@click="emit('sort', sort === 'newest' ? 'oldest' : 'newest')">
							{{ t('webforms', 'Submitted') }}
							<Icon :path="sort === 'newest' ? icons.arrowDown : icons.arrowUp" :size="16" />
						</button>
					</th>
					<th scope="col">
						{{ t('webforms', 'Sent by') }}
					</th>
					<th v-for="column in columns" :key="column.id" scope="col">
						{{ column.label }}
					</th>
				</tr>
			</thead>
			<tbody>
				<template v-for="entry in entries" :key="entry.id">
					<tr class="af-table__row"
						:class="{ 'af-table__row--open': expandedId === entry.id }"
						tabindex="0"
						:aria-expanded="expandedId === entry.id"
						@click="emit('toggle', entry.id)"
						@keydown.enter.self.prevent="emit('toggle', entry.id)"
						@keydown.space.self.prevent="emit('toggle', entry.id)">
						<td class="af-table__id">
							{{ entry.id }}
						</td>
						<td class="af-table__when">
							{{ formatTime(entry.submittedAt) }}
						</td>
						<td>{{ entry.userId ?? t('webforms', 'Anonymous') }}</td>
						<td v-for="column in columns" :key="column.id" :title="cellText(versions[String(entry.versionId)] ?? EMPTY, entry.data, column.id)">
							{{ cellText(versions[String(entry.versionId)] ?? EMPTY, entry.data, column.id) }}
						</td>
					</tr>
					<tr v-if="expandedId === entry.id" :id="'af-response-' + entry.id" class="af-table__detail">
						<td :colspan="3 + columns.length">
							<ResponseCard :form-id="formId"
								:entry="entry"
								:definition="versions[String(entry.versionId)] ?? EMPTY"
								expanded
								@toggle="emit('toggle', entry.id)"
								@delete="emit('delete', entry.id)"
								@changed="emit('changed')" />
						</td>
					</tr>
				</template>
			</tbody>
		</table>
	</div>
</template>

<style scoped>
.af-table-wrap {
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.af-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
}

.af-table th,
.af-table td {
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
	text-align: start;
	vertical-align: top;
	border-bottom: 1px solid var(--color-border);
	max-width: 260px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.af-table th {
	position: sticky;
	top: 0;
	background: var(--color-background-hover);
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.af-table__id {
	width: 1%;
	color: var(--color-text-maxcontrast);
}

.af-table__when {
	white-space: nowrap;
}

.af-table__sort {
	all: unset;
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	cursor: pointer;
	font-weight: bold;
	color: inherit;
}

.af-table__sort:focus-visible,
.af-table__row:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: -2px;
}

.af-table__row {
	cursor: pointer;
}

.af-table__row:hover,
.af-table__row--open {
	background: var(--color-background-hover);
}

.af-table__detail > td {
	max-width: none;
	white-space: normal;
	background: var(--color-main-background);
}
</style>
