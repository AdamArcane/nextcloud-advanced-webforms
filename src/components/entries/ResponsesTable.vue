<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ResponseColumn } from '../../entries/columns.ts'
import type { EntrySort } from '../../entries/filter.ts'
import type { EntryRecord, VersionDefinition } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { onBeforeUnmount, onMounted, ref } from 'vue'
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

// An opened response sits in a cell that spans every column, so it would be as wide as the whole table
// and, on a phone, mostly off screen. It is pinned to the left edge at the width that is visible.
const wrap = ref<HTMLElement | null>(null)
const panelWidth = ref(0)
let observer: ResizeObserver | null = null

function measure(): void {
	panelWidth.value = wrap.value?.clientWidth ?? 0
}

onMounted(() => {
	measure()
	if (typeof ResizeObserver !== 'undefined' && wrap.value) {
		observer = new ResizeObserver(measure)
		observer.observe(wrap.value)
	}
})

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
	<div ref="wrap" class="af-table-wrap">
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
							<div class="af-table__panel" :style="panelWidth > 0 ? { width: panelWidth + 'px' } : undefined">
								<ResponseCard :form-id="formId"
									:entry="entry"
									:definition="versions[String(entry.versionId)] ?? EMPTY"
									expanded
									@toggle="emit('toggle', entry.id)"
									@delete="emit('delete', entry.id)"
									@changed="emit('changed')" />
							</div>
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
	padding: 0;
	/* Cells clip for the ellipsis, which would make this cell the thing the panel sticks to. */
	overflow: visible;
	white-space: normal;
	background: var(--color-main-background);
}

.af-table__panel {
	position: sticky;
	inset-inline-start: 0;
	box-sizing: border-box;
	max-width: 100%;
	padding: calc(var(--default-grid-baseline) * 2);
}
</style>
