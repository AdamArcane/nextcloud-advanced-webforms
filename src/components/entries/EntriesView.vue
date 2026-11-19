<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { EntryFilterState } from '../../entries/filter.ts'
import type { Delivery, DeliveryStatus, DraftDefinition, EntryRecord, ResponseStatus, VersionDefinition } from '../../types.ts'

import { n, t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, nextTick, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue'
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'
import { bulkDeleteEntries, bulkSetEntryStatus, deleteEntry, exportUrl, fetchEntrySummary, listDeliveries, listEntries, markEntriesSeen, retryDelivery, setEntryStatus } from '../../api/forms.ts'
import { ApiError } from '../../api/http.ts'
import { defaultColumnIds, loadColumnChoice, responseColumns, saveColumnChoice, visibleColumns } from '../../entries/columns.ts'
import { exportScope, MAX_SELECTED } from '../../entries/exporting.ts'
import type { ExportFormat } from '../../entries/exporting.ts'
import { EMPTY_FILTER, isFilterActive } from '../../entries/filter.ts'
import { findStatus, isNewResponse, statusLabel } from '../../entries/statuses.ts'
import type { EntrySummaryData } from '../../entries/summary.ts'
import FormBar from '../FormBar.vue'
import SegmentedControl from '../builder/SegmentedControl.vue'
import DeliveryRow from './DeliveryRow.vue'
import ResponseCard from './ResponseCard.vue'
import ResponseFilters from './ResponseFilters.vue'
import { CAN_MANAGE_RESPONSES } from './permissions.ts'
import ResponsePage from './ResponsePage.vue'
import ResponseSummary from './ResponseSummary.vue'
import ResponsesTable from './ResponsesTable.vue'

const props = withDefaults(defineProps<{
	formId: number
	title: string
	/** Show this one response on a page of its own, in place of the list. */
	entryId?: number | null
	/** The statuses a response can be given (the administrator's list). */
	statuses?: readonly ResponseStatus[]
	/** How responses are laid out and whether the summary is open: the user's preferences (docs/SCHEMA.md 16.1). */
	responsesView?: 'table' | 'cards'
	responsesSummary?: boolean
	/** The person's role on the form. A Reviewer cannot delete responses or see the delivery log. */
	role?: 'owner' | 'editor' | 'reviewer'
}>(), {
	entryId: null,
	statuses: () => [],
	responsesView: 'table',
	responsesSummary: true,
	role: 'owner',
})

const canManage = props.role !== 'reviewer'
provide(CAN_MANAGE_RESPONSES, canManage)

const emit = defineEmits<{
	/** The owner looked at the list, so this form has no new responses any more. */
	seen: []
	/** The person changed how responses are shown. The app keeps it as their preference. */
	preference: [patch: { responsesView?: 'table' | 'cards', responsesSummary?: boolean }]
}>()

const PAGE = 50
/** Stop looking for a response in older pages after this many pages. */
const MAX_JUMP_PAGES = 40

type Tab = 'responses' | 'deliveries'
type Filter = 'all' | DeliveryStatus

const tab = ref<Tab>('responses')

// ---- Responses -----------------------------------------------------------

const entries = ref<EntryRecord[]>([])
const versions = ref<Record<string, VersionDefinition>>({})
const total = ref(0)
/** How many responses the form has in all, for "12 of 340" when a filter is on. */
const grandTotal = ref(0)
/** A text or answer search that stopped early, so `total` is a lower bound. */
const truncated = ref(false)
const entryFilter = ref<EntryFilterState>({ ...EMPTY_FILTER })
const filtered = computed(() => isFilterActive(entryFilter.value))

// ---- New since the last look, and statuses (docs/SCHEMA.md section 20) ----

/**
 * When the owner last looked at this list before this visit. Responses after it stay marked as new for
 * as long as this view is open, so they do not lose the mark while being read. Undefined until known.
 */
const seenAt = ref<number | null | undefined>(undefined)
const newOnPage = computed(() => entries.value.filter((entry) => isNewResponse(entry, seenAt.value)).length)

async function markSeen(): Promise<void> {
	try {
		seenAt.value = await markEntriesSeen(props.formId)
		emit('seen')
	} catch {
		// Without the moment nothing is marked. The list itself still works.
	}
}

const statusBusyId = ref<number | null>(null)
const statusError = ref(false)

function replaceEntry(updated: EntryRecord): void {
	entries.value = entries.value.map((entry) => (entry.id === updated.id ? updated : entry))
}

async function changeStatus(id: number, status: string | null): Promise<void> {
	statusBusyId.value = id
	statusError.value = false
	try {
		replaceEntry(await setEntryStatus(props.formId, id, status))
	} catch {
		statusError.value = true
	} finally {
		statusBusyId.value = null
	}
}

type ResponsesView = 'table' | 'cards'
// The choice is the user's preference, so it follows them to another device. It shows at once and
// follows the preference when that changes, for example from the Settings dialog.
const view = ref<ResponsesView>(props.responsesView)
watch(() => props.responsesView, (value) => {
	view.value = value
	if (value === 'cards') {
		clearSelection()
	}
})
function setView(value: ResponsesView): void {
	view.value = value
	if (value === 'cards') {
		clearSelection()
	}
	emit('preference', { responsesView: value })
}

const chosenColumns = ref<string[] | null>(loadColumnChoice(props.formId))

// ---- Summary ---------------------------------------------------------------

const summaryOpen = ref(props.responsesSummary)
watch(() => props.responsesSummary, (value) => {
	summaryOpen.value = value
	void loadSummary()
})
const summary = ref<EntrySummaryData | null>(null)
const summaryLoading = ref(false)
const summaryFailed = ref(false)
/** Only the answer to the newest request is shown, so a slow old one cannot overwrite it. */
let summaryRequest = 0

async function loadSummary(): Promise<void> {
	if (!summaryOpen.value) {
		return
	}
	const request = ++summaryRequest
	summaryLoading.value = true
	summaryFailed.value = false
	try {
		const loaded = await fetchEntrySummary(props.formId, entryFilter.value)
		if (request === summaryRequest) {
			summary.value = loaded
		}
	} catch {
		if (request === summaryRequest) {
			summaryFailed.value = true
		}
	} finally {
		if (request === summaryRequest) {
			summaryLoading.value = false
		}
	}
}

function toggleSummary(): void {
	summaryOpen.value = !summaryOpen.value
	emit('preference', { responsesSummary: summaryOpen.value })
	void loadSummary()
}

// ---- Selecting and bulk actions ------------------------------------------

const selected = ref<Set<number>>(new Set())
/** Every response the filter matches is selected, not just the ones loaded. */
const allMatching = ref(false)
const confirmBulk = ref(false)
const bulkBusy = ref(false)
const bulkError = ref<string | null>(null)
const bulkDone = ref<number | null>(null)

const selectedCount = computed(() => (allMatching.value ? total.value : selected.value.size))
const pageAllSelected = computed(() => entries.value.length > 0 && entries.value.every((entry) => selected.value.has(entry.id)))
/** Offer "all N matching" once the whole page is ticked and there is more than the page. */
const canSelectAllMatching = computed(() => pageAllSelected.value && !allMatching.value && total.value > entries.value.length && !truncated.value)
/** Ticked responses travel in the request, so only so many at a time. */
const tooManySelected = computed(() => !allMatching.value && selected.value.size > MAX_SELECTED)

function clearSelection(): void {
	selected.value = new Set()
	allMatching.value = false
}

function selectOne(id: number, on: boolean): void {
	const next = new Set(selected.value)
	if (on) {
		next.add(id)
	} else {
		next.delete(id)
		allMatching.value = false
	}
	selected.value = next
}

function selectPage(on: boolean): void {
	if (!on) {
		clearSelection()
		return
	}
	selected.value = new Set([...selected.value, ...entries.value.map((entry) => entry.id)])
}

async function bulkDelete(): Promise<void> {
	confirmBulk.value = false
	bulkBusy.value = true
	bulkError.value = null
	bulkDone.value = null
	bulkStatusDone.value = null
	try {
		const deleted = await bulkDeleteEntries(props.formId, allMatching.value
			? { filter: entryFilter.value, expected: total.value }
			: { ids: [...selected.value] })
		clearSelection()
		bulkDone.value = deleted
		expandedId.value = null
		await loadEntries(false)
		void refreshFailedCount()
		void loadSummary()
	} catch (error) {
		if (error instanceof ApiError && error.code === 'count_changed') {
			bulkError.value = t('webforms', 'The number of matching responses changed from {before} to {now}, so nothing was deleted. Check the list and try again.', { before: total.value, now: Number(error.data.current ?? 0) })
			clearSelection()
			await loadEntries(false)
		} else if (error instanceof ApiError && error.code === 'validation') {
			bulkError.value = t('webforms', 'That is too many responses to delete at once. Narrow the filter, or select fewer.')
		} else {
			bulkError.value = t('webforms', 'Could not delete the responses.')
		}
	} finally {
		bulkBusy.value = false
	}
}

/** What the last bulk change of status did, for the note above the table. */
const bulkStatusDone = ref<{ changed: number, label: string } | null>(null)

async function bulkStatus(status: string | null): Promise<void> {
	bulkBusy.value = true
	bulkError.value = null
	bulkDone.value = null
	bulkStatusDone.value = null
	try {
		const which = allMatching.value ? { filter: entryFilter.value, expected: total.value } : { ids: [...selected.value] }
		const changed = await bulkSetEntryStatus(props.formId, { ...which, status })
		const chosen = findStatus(props.statuses, status)
		bulkStatusDone.value = { changed, label: chosen ? statusLabel(chosen) : t('webforms', 'No status') }
		clearSelection()
		await loadEntries(false)
	} catch (error) {
		if (error instanceof ApiError && error.code === 'count_changed') {
			bulkError.value = t('webforms', 'The number of matching responses changed from {before} to {now}, so nothing was changed. Check the list and try again.', { before: total.value, now: Number(error.data.current ?? 0) })
			clearSelection()
			await loadEntries(false)
		} else if (error instanceof ApiError && error.code === 'validation') {
			bulkError.value = t('webforms', 'That is too many responses to change at once. Narrow the filter, or select fewer.')
		} else {
			bulkError.value = t('webforms', 'Could not change the status of the responses.')
		}
	} finally {
		bulkBusy.value = false
	}
}

function onBulkStatus(event: Event): void {
	const select = event.target as HTMLSelectElement
	const value = select.value
	// Back to the prompt, so the same status can be picked again later.
	select.value = '__pick'
	if (value !== '__pick') {
		void bulkStatus(value === '' ? null : value)
	}
}

const scope = computed(() => exportScope(selected.value.size, allMatching.value, filtered.value))
const scopeLabel = computed(() => {
	switch (scope.value) {
		case 'selected': return t('webforms', '{n} selected responses', { n: selected.value.size })
		case 'matching': return t('webforms', '{n} matching responses', { n: total.value })
		default: return t('webforms', 'All {n} responses', { n: grandTotal.value })
	}
})

/** The download for the current scope: ticked responses, the filter, or everything, and the chosen columns. */
function downloadUrl(format: ExportFormat): string {
	return exportUrl(props.formId, {
		format,
		ids: scope.value === 'selected' ? [...selected.value] : undefined,
		filter: scope.value === 'selected' ? undefined : entryFilter.value,
		columns: chosenColumns.value ?? undefined,
	})
}
const loading = ref(true)
const loadingMore = ref(false)
const loadFailed = ref(false)
const expandedId = ref<number | null>(null)
const confirmDeleteId = ref<number | null>(null)
const deleteFailed = ref(false)

async function loadEntries(append: boolean): Promise<void> {
	if (append) {
		loadingMore.value = true
	} else {
		loading.value = true
	}
	loadFailed.value = false
	try {
		const page = await listEntries(props.formId, PAGE, append ? entries.value.length : 0, entryFilter.value)
		total.value = page.total
		grandTotal.value = page.grandTotal
		truncated.value = page.truncated
		versions.value = { ...versions.value, ...page.versions }
		entries.value = append ? [...entries.value, ...page.entries] : page.entries
	} catch {
		loadFailed.value = true
	} finally {
		loading.value = false
		loadingMore.value = false
	}
}

const hasMore = computed(() => entries.value.length < total.value)

const allColumns = computed(() => responseColumns(versions.value))
const shownColumns = computed(() => visibleColumns(allColumns.value, chosenColumns.value ?? defaultColumnIds(allColumns.value)))

/** The newest version the listed responses belong to, so the answers filter can offer its questions. */
const filterDefinition = computed<DraftDefinition | null>(() => {
	const newest = Object.keys(versions.value).sort((a, b) => Number(b) - Number(a))[0]
	const definition = newest === undefined ? undefined : versions.value[newest]
	return definition ? ({ schemaVersion: 1, title: '', settings: {}, rules: [], actions: [], ...definition } as DraftDefinition) : null
})

function applyFilter(next: EntryFilterState): void {
	const previous = entryFilter.value
	entryFilter.value = next
	expandedId.value = null
	clearSelection()
	bulkDone.value = null
	bulkStatusDone.value = null
	bulkError.value = null
	void loadEntries(false)
	// Changing only the order does not change what the responses add up to.
	if (JSON.stringify({ ...next, sort: '' }) !== JSON.stringify({ ...previous, sort: '' })) {
		void loadSummary()
	}
}

function toggleColumn(id: string, on: boolean): void {
	const current = chosenColumns.value ?? defaultColumnIds(allColumns.value)
	chosenColumns.value = on ? [...new Set([...current, id])] : current.filter((existing) => existing !== id)
	saveColumnChoice(props.formId, chosenColumns.value)
}

function definitionFor(entry: EntryRecord): VersionDefinition {
	return versions.value[String(entry.versionId)] ?? { fields: {}, pages: [] }
}

async function confirmDelete(): Promise<void> {
	const id = confirmDeleteId.value
	confirmDeleteId.value = null
	if (id === null) {
		return
	}
	deleteFailed.value = false
	try {
		await deleteEntry(props.formId, id)
		entries.value = entries.value.filter((entry) => entry.id !== id)
		total.value = Math.max(0, total.value - 1)
		grandTotal.value = Math.max(0, grandTotal.value - 1)
		void loadSummary()
		if (expandedId.value === id) {
			expandedId.value = null
		}
		void refreshFailedCount()
	} catch {
		deleteFailed.value = true
	}
}

/** Show a response from the Deliveries tab, loading older pages until it turns up. */
async function showEntry(entryId: number): Promise<void> {
	tab.value = 'responses'
	for (let pages = 0; pages < MAX_JUMP_PAGES && !entries.value.some((entry) => entry.id === entryId) && hasMore.value; pages++) {
		await loadEntries(true)
	}
	if (entries.value.some((entry) => entry.id === entryId)) {
		expandedId.value = entryId
		await nextTick()
		document.getElementById('af-response-' + entryId)?.scrollIntoView({ block: 'center' })
	}
}

// ---- Deliveries ----------------------------------------------------------

const deliveries = ref<Delivery[]>([])
const deliveryTotal = ref(0)
const deliveriesLoading = ref(false)
const deliveriesMore = ref(false)
const deliveriesFailed = ref(false)
const filter = ref<Filter>('all')
const retrying = ref<number | null>(null)
const failedCount = ref(0)
let deliveriesLoaded = false

async function refreshFailedCount(): Promise<void> {
	if (!canManage) {
		return
	}
	try {
		failedCount.value = (await listDeliveries(props.formId, { status: 'failed', limit: 1 })).total
	} catch {
		// The badge is a convenience; the tab itself reports errors.
	}
}

async function loadDeliveries(append: boolean): Promise<void> {
	if (append) {
		deliveriesMore.value = true
	} else {
		deliveriesLoading.value = true
	}
	deliveriesFailed.value = false
	try {
		const page = await listDeliveries(props.formId, {
			status: filter.value === 'all' ? undefined : filter.value,
			limit: PAGE,
			offset: append ? deliveries.value.length : 0,
		})
		deliveryTotal.value = page.total
		deliveries.value = append ? [...deliveries.value, ...page.rows] : page.rows
		deliveriesLoaded = true
	} catch {
		deliveriesFailed.value = true
	} finally {
		deliveriesLoading.value = false
		deliveriesMore.value = false
	}
}

// Deliveries change on their own (the background job sends, retries, fails), so while any is
// waiting, the list refreshes quietly. Nothing refreshes once everything has settled.
const REFRESH_MS = 10_000
const now = ref(Math.floor(Date.now() / 1000))
const refreshing = ref(false)

async function refreshDeliveries(): Promise<void> {
	if (refreshing.value || deliveriesLoading.value || deliveriesMore.value) {
		return
	}
	refreshing.value = true
	try {
		// Re-read as many rows as are on screen, so "Show more" pages are not thrown away.
		const page = await listDeliveries(props.formId, {
			status: filter.value === 'all' ? undefined : filter.value,
			limit: Math.min(Math.max(PAGE, deliveries.value.length), 100),
			offset: 0,
		})
		deliveryTotal.value = page.total
		deliveries.value = page.rows
		deliveriesFailed.value = false
		void refreshFailedCount()
	} catch {
		// Keep what is on screen; the next tick tries again.
	} finally {
		refreshing.value = false
	}
}

/** Waiting for two minutes past the planned time: nothing is picking them up. */
const OVERDUE_SECONDS = 120
const overdue = computed(() => deliveries.value.some((row) => row.status === 'queued' && row.nextAttemptAt !== null && row.nextAttemptAt < now.value - OVERDUE_SECONDS))

let refreshTimer: ReturnType<typeof setInterval> | undefined

function tick(): void {
	now.value = Math.floor(Date.now() / 1000)
	if (tab.value === 'deliveries' && !document.hidden && deliveries.value.some((row) => row.status === 'queued')) {
		void refreshDeliveries()
	}
}

onBeforeUnmount(() => clearInterval(refreshTimer))

function openTab(value: string | number): void {
	tab.value = value as Tab
	if (tab.value === 'deliveries' && !deliveriesLoaded) {
		void loadDeliveries(false)
	}
}

function setFilter(value: string | number): void {
	filter.value = value as Filter
	void loadDeliveries(false)
}

async function retry(delivery: Delivery): Promise<void> {
	retrying.value = delivery.id
	deliveriesFailed.value = false
	try {
		const updated = await retryDelivery(props.formId, delivery.id)
		deliveries.value = deliveries.value.map((row) => (row.id === updated.id ? updated : row))
		void refreshFailedCount()
	} catch {
		deliveriesFailed.value = true
	} finally {
		retrying.value = null
	}
}

const deliveryHasMore = computed(() => deliveries.value.length < deliveryTotal.value)

let listStarted = false

/** The first time the list itself is on screen. Opening one response by its address is not a look at the list. */
function startList(): void {
	listStarted = true
	void markSeen()
	void loadEntries(false)
	void refreshFailedCount()
	void loadSummary()
}

watch(() => props.entryId, (entryId) => {
	if (entryId === null && !listStarted) {
		startList()
	}
}, { immediate: true })

onMounted(() => {
	refreshTimer = setInterval(tick, REFRESH_MS)
})
</script>

<template>
	<NcAppContent class="af-entries">
		<FormBar :form-id="formId" active="responses" :title="title">
			<template #meta>
				<span class="af-entries__count">
					{{ filtered ? t('webforms', '{n} of {all} responses', { n: total, all: grandTotal }) : t('webforms', '{n} responses', { n: total }) }}
				</span>
			</template>
			<template #end>
				<NcActions :name="t('webforms', 'Download')"
					:aria-label="t('webforms', 'Download responses')"
					:disabled="grandTotal === 0 || tooManySelected"
					variant="secondary"
					force-name>
					<template #icon>
						<Icon :path="icons.download" />
					</template>
					<NcActionCaption :name="scopeLabel" />
					<NcActionLink :href="downloadUrl('csv')" download>
						<template #icon>
							<Icon :path="icons.download" />
						</template>
						{{ t('webforms', 'CSV (.csv)') }}
					</NcActionLink>
					<NcActionLink :href="downloadUrl('xlsx')" download>
						<template #icon>
							<Icon :path="icons.download" />
						</template>
						{{ t('webforms', 'Excel (.xlsx)') }}
					</NcActionLink>
				</NcActions>
			</template>
		</FormBar>

		<div v-if="entryId !== null" class="af-entries__body">
			<ResponsePage :form-id="formId"
				:entry-id="entryId"
				:title="title"
				:statuses="statuses"
				@updated="replaceEntry"
				@changed="refreshFailedCount" />
		</div>

		<div v-else class="af-entries__body">
			<SegmentedControl v-if="canManage"
				:model-value="tab"
				class="af-entries__tabs"
				:group-label="t('webforms', 'What to show')"
				:options="[
					{ value: 'responses', label: t('webforms', 'Responses'), icon: icons.responses },
					{ value: 'deliveries', icon: icons.deliveries, label: failedCount > 0 ? t('webforms', 'Deliveries ({n} failed)', { n: failedCount }) : t('webforms', 'Deliveries') },
				]"
				@update:model-value="openTab" />

			<NcNoteCard v-if="failedCount > 0 && tab === 'responses'" type="warning">
				{{ t('webforms', '{n} emails or webhooks could not be sent.', { n: failedCount }) }}
				<NcButton variant="tertiary" @click="() => { openTab('deliveries'); setFilter('failed') }">
					{{ t('webforms', 'Review') }}
				</NcButton>
			</NcNoteCard>

			<!-- Responses -->
			<template v-if="tab === 'responses'">
				<ResponseFilters v-if="grandTotal > 0 || filtered"
					:model-value="entryFilter"
					:definition="filterDefinition"
					:statuses="statuses"
					@update:model-value="applyFilter" />

				<div v-if="loading" class="af-entries__center">
					<NcLoadingIcon :size="44" />
				</div>
				<NcNoteCard v-else-if="loadFailed && entries.length === 0" type="error">
					{{ t('webforms', 'Could not load the responses.') }}
					<NcButton variant="tertiary" @click="loadEntries(false)">
						{{ t('webforms', 'Try again') }}
					</NcButton>
				</NcNoteCard>
				<NcEmptyContent v-else-if="entries.length === 0 && filtered"
					:name="t('webforms', 'No responses match')"
					:description="t('webforms', 'Try a different search, or clear the filters.')">
					<template #action>
						<NcButton variant="secondary" @click="applyFilter({ ...EMPTY_FILTER, sort: entryFilter.sort })">
							{{ t('webforms', 'Clear filters') }}
						</NcButton>
					</template>
				</NcEmptyContent>
				<NcEmptyContent v-else-if="entries.length === 0"
					:name="t('webforms', 'No responses yet')"
					:description="t('webforms', 'Responses appear here as soon as someone sends the form.')" />

				<template v-else>
					<NcNoteCard v-if="deleteFailed" type="error">
						{{ t('webforms', 'Could not delete the response.') }}
					</NcNoteCard>
					<NcNoteCard v-if="truncated" type="info">
						{{ t('webforms', 'Searched the first 20,000 responses. There may be more matches, narrow the dates to find them.') }}
					</NcNoteCard>

					<ResponseSummary v-if="summaryOpen"
						:form-id="formId"
						:summary="summary"
						:loading="summaryLoading"
						:failed="summaryFailed" />

					<NcNoteCard v-if="bulkDone !== null" type="success">
						{{ t('webforms', 'Deleted {n} responses.', { n: bulkDone }) }}
					</NcNoteCard>
					<NcNoteCard v-if="bulkStatusDone !== null" type="success">
						{{ t('webforms', 'Set {n} responses to "{status}".', { n: bulkStatusDone.changed, status: bulkStatusDone.label }) }}
					</NcNoteCard>
					<NcNoteCard v-if="statusError" type="error">
						{{ t('webforms', 'Could not change the status.') }}
					</NcNoteCard>
					<NcNoteCard v-if="bulkError" type="error">
						{{ bulkError }}
					</NcNoteCard>

					<div v-if="selectedCount > 0 && view === 'table'" class="af-entries__bulk" role="region" :aria-label="t('webforms', 'Selected responses')">
						<strong>{{ allMatching ? t('webforms', 'All {n} matching responses are selected', { n: total }) : t('webforms', '{n} selected', { n: selected.size }) }}</strong>
						<NcButton v-if="canSelectAllMatching" variant="tertiary" @click="allMatching = true">
							{{ t('webforms', 'Select all {n} matching', { n: total }) }}
						</NcButton>
						<span v-if="tooManySelected" class="af-entries__hint">
							{{ t('webforms', 'Select up to {max} at a time, or use "Select all matching".', { max: MAX_SELECTED }) }}
						</span>
						<span class="af-entries__spacer" />
						<select v-if="statuses.length > 0"
							class="af-entries__setstatus"
							:aria-label="t('webforms', 'Set the status of the selected responses')"
							:disabled="tooManySelected || bulkBusy"
							@change="onBulkStatus">
							<option value="__pick" selected>
								{{ t('webforms', 'Set status…') }}
							</option>
							<option value="">
								{{ t('webforms', 'No status') }}
							</option>
							<option v-for="status in statuses" :key="status.id" :value="status.id">
								{{ statusLabel(status) }}
							</option>
						</select>
						<NcButton v-if="canManage" variant="error" :disabled="tooManySelected || bulkBusy" @click="confirmBulk = true">
							<template #icon>
								<NcLoadingIcon v-if="bulkBusy" :size="20" />
								<Icon v-else :path="icons.delete" />
							</template>
							{{ t('webforms', 'Delete') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="bulkBusy" @click="clearSelection">
							{{ t('webforms', 'Clear selection') }}
						</NcButton>
					</div>

					<div class="af-entries__tools">
						<SegmentedControl :model-value="view"
							:group-label="t('webforms', 'How to show responses')"
							icon-only
							:options="[
								{ value: 'table', label: t('webforms', 'Table'), icon: icons.tableView },
								{ value: 'cards', label: t('webforms', 'Cards'), icon: icons.cardView },
							]"
							@update:model-value="(value) => setView(value === 'cards' ? 'cards' : 'table')" />
						<NcButton :variant="summaryOpen ? 'primary' : 'secondary'" :aria-pressed="summaryOpen" @click="toggleSummary">
							<template #icon>
								<Icon :path="icons.summary" />
							</template>
							{{ t('webforms', 'Summary') }}
						</NcButton>
						<span v-if="newOnPage > 0" class="af-entries__newcount" role="status">
							{{ n('webforms', '%n new since your last visit', '%n new since your last visit', newOnPage) }}
						</span>
						<span class="af-entries__spacer" />
						<NcActions v-if="view === 'table'" :aria-label="t('webforms', 'Choose columns')" :name="t('webforms', 'Columns')">
							<template #icon>
								<Icon :path="icons.columns" />
							</template>
							<NcActionCheckbox v-for="column in allColumns"
								:key="column.id"
								:model-value="shownColumns.some((shown) => shown.id === column.id)"
								@update:model-value="(on: boolean) => toggleColumn(column.id, on)">
								{{ column.label }}
							</NcActionCheckbox>
						</NcActions>
					</div>

					<ResponsesTable v-if="view === 'table'"
						:form-id="formId"
						:entries="entries"
						:versions="versions"
						:columns="shownColumns"
						:expanded-id="expandedId"
						:sort="entryFilter.sort"
						:selected="selected"
						:statuses="statuses"
						:seen-at="seenAt"
						:busy-id="statusBusyId"
						@status="changeStatus"
						@updated="replaceEntry"
						@select="selectOne"
						@select-page="selectPage"
						@toggle="(id) => (expandedId = expandedId === id ? null : id)"
						@delete="(id) => (confirmDeleteId = id)"
						@changed="refreshFailedCount"
						@sort="(sort) => applyFilter({ ...entryFilter, sort })" />
					<ul v-else class="af-entries__list">
						<li v-for="entry in entries" :id="'af-response-' + entry.id" :key="entry.id">
							<ResponseCard :form-id="formId"
								:entry="entry"
								:definition="definitionFor(entry)"
								:expanded="expandedId === entry.id"
								:statuses="statuses"
								:is-new="isNewResponse(entry, seenAt)"
								:busy="statusBusyId === entry.id"
								@status="(value) => changeStatus(entry.id, value)"
								@updated="replaceEntry"
								@toggle="expandedId = expandedId === entry.id ? null : entry.id"
								@delete="confirmDeleteId = entry.id"
								@changed="refreshFailedCount" />
						</li>
					</ul>
					<div v-if="hasMore" class="af-entries__more">
						<NcButton variant="secondary" :disabled="loadingMore" @click="loadEntries(true)">
							{{ loadingMore ? t('webforms', 'Loading…') : t('webforms', 'Show more') }}
						</NcButton>
						<span class="af-entries__hint">{{ t('webforms', 'Showing {shown} of {total}', { shown: entries.length, total }) }}</span>
					</div>
					<NcNoteCard v-if="loadFailed" type="error">
						{{ t('webforms', 'Could not load more responses.') }}
					</NcNoteCard>
				</template>
			</template>

			<!-- Deliveries -->
			<template v-else>
				<SegmentedControl :model-value="filter"
					class="af-entries__filter"
					:group-label="t('webforms', 'Filter deliveries')"
					:options="[
						{ value: 'all', label: t('webforms', 'All') },
						{ value: 'failed', label: t('webforms', 'Failed') },
						{ value: 'queued', label: t('webforms', 'Waiting') },
						{ value: 'success', label: t('webforms', 'Sent') },
						{ value: 'skipped', label: t('webforms', 'Skipped') },
					]"
					@update:model-value="setFilter" />
				<div class="af-entries__refresh">
					<NcButton variant="secondary"
						:disabled="refreshing || deliveriesLoading"
						@click="refreshDeliveries">
						<template #icon>
							<NcLoadingIcon v-if="refreshing" :size="20" />
							<Icon v-else :path="icons.refresh" />
						</template>
						{{ t('webforms', 'Refresh') }}
					</NcButton>
					<span v-if="deliveries.some((row) => row.status === 'queued')" class="af-entries__hint">
						{{ t('webforms', 'This list also refreshes by itself every few seconds while deliveries are waiting.') }}
					</span>
				</div>
				<NcNoteCard v-if="overdue" type="warning">
					{{ t('webforms', 'Some deliveries are overdue. Nextcloud sends them with its background jobs, which do not seem to be running. Ask your administrator to switch background jobs to Cron (Administration settings, Basic settings).') }}
				</NcNoteCard>

				<div v-if="deliveriesLoading" class="af-entries__center">
					<NcLoadingIcon :size="44" />
				</div>
				<NcNoteCard v-else-if="deliveriesFailed && deliveries.length === 0" type="error">
					{{ t('webforms', 'Could not load the deliveries.') }}
					<NcButton variant="tertiary" @click="loadDeliveries(false)">
						{{ t('webforms', 'Try again') }}
					</NcButton>
				</NcNoteCard>
				<NcEmptyContent v-else-if="deliveries.length === 0"
					:name="filter === 'all' ? t('webforms', 'Nothing sent yet') : t('webforms', 'Nothing here')"
					:description="filter === 'all' ? t('webforms', 'Emails and webhooks from your form\'s actions are listed here, with what happened to each.') : t('webforms', 'No deliveries match this filter.')" />

				<template v-else>
					<NcNoteCard v-if="deliveriesFailed" type="error">
						{{ t('webforms', 'That did not work. Try again in a moment.') }}
					</NcNoteCard>
					<p class="af-entries__hint">
						{{ t('webforms', 'Failed deliveries are tried again automatically, up to five times. Trying again by hand starts over and is sent within a minute.') }}
					</p>
					<ul class="af-entries__list">
						<DeliveryRow v-for="delivery in deliveries"
							:key="delivery.id"
							:delivery="delivery"
							show-entry
							:busy="retrying === delivery.id"
							:now="now"
							@retry="retry(delivery)"
							@open-entry="showEntry(delivery.entryId)" />
					</ul>
					<div v-if="deliveryHasMore" class="af-entries__more">
						<NcButton variant="secondary" :disabled="deliveriesMore" @click="loadDeliveries(true)">
							{{ t('webforms', 'Show more') }}
						</NcButton>
					</div>
				</template>
			</template>
		</div>

		<NcDialog v-if="confirmDeleteId !== null"
			:name="t('webforms', 'Delete this response?')"
			:message="t('webforms', 'The answers and the record of what was sent are deleted. This cannot be undone.')"
			:buttons="[
				{ label: t('webforms', 'Cancel'), variant: 'tertiary', callback: () => { confirmDeleteId = null } },
				{ label: t('webforms', 'Delete'), variant: 'error', callback: confirmDelete },
			]"
			@closing="confirmDeleteId = null" />

		<NcDialog v-if="confirmBulk"
			:name="t('webforms', 'Delete {n} responses?', { n: selectedCount })"
			:message="allMatching
				? t('webforms', 'Every response that matches the filter is deleted, with its answers, uploaded files and the record of what was sent. This cannot be undone.')
				: t('webforms', 'The selected responses are deleted, with their answers, uploaded files and the record of what was sent. This cannot be undone.')"
			:buttons="[
				{ label: t('webforms', 'Cancel'), variant: 'tertiary', callback: () => { confirmBulk = false } },
				{ label: t('webforms', 'Delete {n}', { n: selectedCount }), variant: 'error', callback: bulkDelete },
			]"
			@closing="confirmBulk = false" />
	</NcAppContent>
</template>

<style scoped>
.af-entries {
	display: flex;
	flex-direction: column;
}

.af-entries__count,
.af-entries__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.af-entries__body {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	box-sizing: border-box;
	width: 100%;
	max-width: 900px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 12);
}

.af-entries__tabs {
	max-width: 360px;
}

.af-entries__filter {
	max-width: 520px;
}

.af-entries__refresh {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.af-entries__list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-entries__more {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
}

.af-entries__center {
	display: flex;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 10) 0;
}
.af-entries__bulk {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-primary-element);
	border-radius: var(--border-radius-large);
	background: var(--color-primary-element-light, var(--color-background-hover));
}

.af-entries__setstatus {
	min-height: var(--default-clickable-area);
	margin: 0;
}

.af-entries__newcount {
	padding: 0 10px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 13px;
	font-weight: bold;
	line-height: 24px;
}

.af-entries__spacer {
	flex: 1 1 auto;
}

.af-entries__tools {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}
</style>
