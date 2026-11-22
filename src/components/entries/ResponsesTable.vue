<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ResponseColumn } from '../../entries/columns.ts'
import type { EntrySort } from '../../entries/filter.ts'
import type { EntryRecord, ResponseStatus, VersionDefinition } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { cellText } from '../../entries/columns.ts'
import { formatTime } from '../../entries/format.ts'
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'
import { isNewResponse } from '../../entries/statuses.ts'
import ResponseCard from './ResponseCard.vue'
import StatusSelect from './StatusSelect.vue'
import { useCanManageResponses } from './permissions.ts'
import WaitlistTag from './WaitlistTag.vue'

const props = defineProps<{
	formId: number
	entries: EntryRecord[]
	versions: Record<string, VersionDefinition>
	columns: ResponseColumn[]
	expandedId: number | null
	sort: EntrySort
	/** The ids of the responses that are ticked. */
	selected: ReadonlySet<number>
	/** The statuses a response can be given. None hides the column. */
	statuses?: readonly ResponseStatus[]
	/** When the owner last looked at this list before this visit: later responses are marked new. */
	seenAt?: number | null
	/** The response whose status is being saved, if any. */
	busyId?: number | null
}>()

const emit = defineEmits<{
	toggle: [id: number]
	delete: [id: number]
	changed: []
	sort: [sort: EntrySort]
	select: [id: number, on: boolean]
	/** Tick or untick every response on the page. */
	selectPage: [on: boolean]
	/** The owner picked a status for a response, or none. */
	status: [id: number, status: string | null]
	/** A response changed on the server (its note). */
	updated: [entry: EntryRecord]
	/** The owner took a response off the waiting list. The parent saves it. */
	promote: [id: number]
}>()

const canManage = useCanManageResponses()

const hasStatuses = computed(() => (props.statuses?.length ?? 0) > 0)

const EMPTY: VersionDefinition = { fields: {}, pages: [] }

const pageSelected = computed(() => props.entries.length > 0 && props.entries.every((entry) => props.selected.has(entry.id)))
const someSelected = computed(() => !pageSelected.value && props.entries.some((entry) => props.selected.has(entry.id)))

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
					<th scope="col" class="af-table__check">
						<input type="checkbox"
							:checked="pageSelected"
							:indeterminate.prop="someSelected"
							:aria-label="t('webforms', 'Select all responses on this page')"
							@change="emit('selectPage', ($event.target as HTMLInputElement).checked)">
					</th>
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
					<th v-if="hasStatuses" scope="col">
						{{ t('webforms', 'Status') }}
					</th>
					<th v-for="column in columns" :key="column.id" scope="col">
						{{ column.label }}
					</th>
				</tr>
			</thead>
			<tbody>
				<template v-for="entry in entries" :key="entry.id">
					<tr class="af-table__row"
						:class="{ 'af-table__row--open': expandedId === entry.id, 'af-table__row--selected': selected.has(entry.id), 'af-table__row--new': isNewResponse(entry, seenAt) }"
						tabindex="0"
						:aria-expanded="expandedId === entry.id"
						@click="emit('toggle', entry.id)"
						@keydown.enter.self.prevent="emit('toggle', entry.id)"
						@keydown.space.self.prevent="emit('toggle', entry.id)">
						<td class="af-table__check" @click.stop>
							<input type="checkbox"
								:checked="selected.has(entry.id)"
								:aria-label="t('webforms', 'Select response {id}', { id: entry.id })"
								@change="emit('select', entry.id, ($event.target as HTMLInputElement).checked)">
						</td>
						<td class="af-table__id" @click.stop>
							<a :href="'#/f/' + formId + '/responses/' + entry.id"
								:title="t('webforms', 'Open response {id} on its own page', { id: entry.id })"
								:aria-label="t('webforms', 'Open response {id} on its own page', { id: entry.id })">{{ entry.id }}</a>
						</td>
						<td class="af-table__when">
							<span v-if="isNewResponse(entry, seenAt)" class="af-table__new">{{ t('webforms', 'New') }}</span>
							{{ formatTime(entry.submittedAt) }}
							<span v-if="entry.waitlisted" class="af-table__waitlist" @click.stop>
								<WaitlistTag />
								<NcButton v-if="canManage"
									variant="tertiary"
									:disabled="busyId === entry.id"
									:aria-label="t('webforms', 'Promote response {id} off the waiting list', { id: entry.id })"
									@click="emit('promote', entry.id)">
									{{ t('webforms', 'Promote') }}
								</NcButton>
							</span>
							<span v-if="entry.note" class="af-table__noted" :title="t('webforms', 'Has a note')">
								<Icon :path="icons.note" :size="16" />
							</span>
						</td>
						<td>{{ entry.userId ?? t('webforms', 'Anonymous') }}</td>
						<td v-if="hasStatuses" class="af-table__status" @click.stop>
							<StatusSelect :statuses="statuses ?? []"
								:model-value="entry.status"
								:label="t('webforms', 'Status of response {id}', { id: entry.id })"
								:disabled="busyId === entry.id"
								@update:model-value="(value) => emit('status', entry.id, value)" />
						</td>
						<td v-for="column in columns" :key="column.id" :title="cellText(versions[String(entry.versionId)] ?? EMPTY, entry.data, column.id)">
							{{ cellText(versions[String(entry.versionId)] ?? EMPTY, entry.data, column.id) }}
						</td>
					</tr>
					<tr v-if="expandedId === entry.id" :id="'af-response-' + entry.id" class="af-table__detail">
						<td :colspan="4 + (hasStatuses ? 1 : 0) + columns.length">
							<div class="af-table__panel" :style="panelWidth > 0 ? { width: panelWidth + 'px' } : undefined">
								<ResponseCard :form-id="formId"
									:entry="entry"
									:definition="versions[String(entry.versionId)] ?? EMPTY"
									expanded
									:statuses="statuses"
									:is-new="isNewResponse(entry, seenAt)"
									:busy="busyId === entry.id"
									@status="(value) => emit('status', entry.id, value)"
									@promote="emit('promote', entry.id)"
									@updated="(saved) => emit('updated', saved)"
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

.af-table__check {
	width: 1%;
	padding-inline-end: 0;
}

.af-table__check input {
	margin: 0;
	cursor: pointer;
}

.af-table__row--selected {
	background: var(--color-primary-element-light-hover, var(--color-background-hover));
}

.af-table__when {
	white-space: nowrap;
}

.af-table__id a {
	color: inherit;
	text-decoration: underline;
}

.af-table__new {
	margin-inline-end: 6px;
	padding: 0 8px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 12px;
	font-weight: bold;
	line-height: 20px;
}

.af-table__row--new .af-table__when {
	font-weight: bold;
}

.af-table__noted {
	display: inline-flex;
	margin-inline-start: 4px;
	color: var(--color-text-maxcontrast);
	vertical-align: middle;
}

.af-table__waitlist {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin-inline-start: 6px;
}

.af-table .af-table__status {
	padding-block: var(--default-grid-baseline);
	cursor: default;
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
