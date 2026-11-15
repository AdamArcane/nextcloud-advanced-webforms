<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Delivery, DeliveryStatus, EntryRecord, VersionDefinition } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'
import { deleteEntry, exportUrl, listDeliveries, listEntries, retryDelivery } from '../../api/forms.ts'
import FormBar from '../FormBar.vue'
import SegmentedControl from '../builder/SegmentedControl.vue'
import DeliveryRow from './DeliveryRow.vue'
import ResponseCard from './ResponseCard.vue'

const props = defineProps<{
	formId: number
	title: string
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
		const page = await listEntries(props.formId, PAGE, append ? entries.value.length : 0)
		total.value = page.total
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

onMounted(() => {
	refreshTimer = setInterval(tick, REFRESH_MS)
	void loadEntries(false)
	void refreshFailedCount()
})
</script>

<template>
	<NcAppContent class="af-entries">
		<FormBar :form-id="formId" active="responses" :title="title">
			<template #meta>
				<span class="af-entries__count">{{ t('webforms', '{n} responses', { n: total }) }}</span>
			</template>
			<template #end>
				<NcButton variant="secondary" :href="exportUrl(formId)" :disabled="total === 0">
					<template #icon>
						<Icon :path="icons.download" />
					</template>
					{{ t('webforms', 'Download CSV') }}
				</NcButton>
			</template>
		</FormBar>

		<div class="af-entries__body">
			<SegmentedControl :model-value="tab"
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
				<div v-if="loading" class="af-entries__center">
					<NcLoadingIcon :size="44" />
				</div>
				<NcNoteCard v-else-if="loadFailed && entries.length === 0" type="error">
					{{ t('webforms', 'Could not load the responses.') }}
					<NcButton variant="tertiary" @click="loadEntries(false)">
						{{ t('webforms', 'Try again') }}
					</NcButton>
				</NcNoteCard>
				<NcEmptyContent v-else-if="entries.length === 0"
					:name="t('webforms', 'No responses yet')"
					:description="t('webforms', 'Responses appear here as soon as someone sends the form.')" />

				<template v-else>
					<NcNoteCard v-if="deleteFailed" type="error">
						{{ t('webforms', 'Could not delete the response.') }}
					</NcNoteCard>
					<ul class="af-entries__list">
						<li v-for="entry in entries" :id="'af-response-' + entry.id" :key="entry.id">
							<ResponseCard :form-id="formId"
								:entry="entry"
								:definition="definitionFor(entry)"
								:expanded="expandedId === entry.id"
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
</style>
