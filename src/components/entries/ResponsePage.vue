<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Delivery, EntryDetail, EntryRecord, ResponseStatus } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, ref, watch } from 'vue'
import { getEntry, listDeliveries, retryDelivery, setEntryStatus } from '../../api/forms.ts'
import { ApiError } from '../../api/http.ts'
import { answerLines, entryFileUrl, formatTime } from '../../entries/format.ts'
import { icons } from '../../icons.ts'
import { formatSize } from '../../uploads.ts'
import Icon from '../common/Icon.vue'
import DeliveryRow from './DeliveryRow.vue'
import NoteEditor from './NoteEditor.vue'
import StatusSelect from './StatusSelect.vue'

// One response on a page of its own (docs/SCHEMA.md section 20.4): every question of its version with
// its answer, the status, the note and what was sent. Printing shows the answers only.
const props = defineProps<{
	formId: number
	entryId: number
	title: string
	statuses: readonly ResponseStatus[]
}>()

const emit = defineEmits<{
	/** The status or the note changed, so a list that shows this response is out of date. */
	updated: [entry: EntryRecord]
	/** A delivery was retried. */
	changed: []
}>()

const detail = ref<EntryDetail | null>(null)
const loading = ref(true)
const missing = ref(false)
const failed = ref(false)
const statusBusy = ref(false)
const statusFailed = ref(false)
const deliveries = ref<Delivery[] | null>(null)
const deliveriesFailed = ref(false)
const retrying = ref<number | null>(null)
/** Only the answer for the response on screen is used, so stepping quickly never shows a stale one. */
let request = 0

const lines = computed(() => (detail.value ? answerLines(detail.value.version, detail.value.entry.data) : []))
const listHref = computed(() => '#/f/' + props.formId + '/responses')

async function load(): Promise<void> {
	const mine = ++request
	loading.value = true
	missing.value = false
	failed.value = false
	statusFailed.value = false
	deliveries.value = null
	deliveriesFailed.value = false
	try {
		const loaded = await getEntry(props.formId, props.entryId)
		if (mine !== request) {
			return
		}
		detail.value = loaded
	} catch (error) {
		if (mine !== request) {
			return
		}
		detail.value = null
		if (error instanceof ApiError && error.status === 404) {
			missing.value = true
		} else {
			failed.value = true
		}
		return
	} finally {
		if (mine === request) {
			loading.value = false
		}
	}
	try {
		const rows = (await listDeliveries(props.formId, { entryId: props.entryId, limit: 50 })).rows
		if (mine === request) {
			deliveries.value = rows
		}
	} catch {
		if (mine === request) {
			deliveriesFailed.value = true
		}
	}
}

watch(() => [props.formId, props.entryId], () => void load(), { immediate: true })

function apply(entry: EntryRecord): void {
	if (detail.value && detail.value.entry.id === entry.id) {
		detail.value = { ...detail.value, entry }
		emit('updated', entry)
	}
}

async function changeStatus(status: string | null): Promise<void> {
	statusBusy.value = true
	statusFailed.value = false
	try {
		apply(await setEntryStatus(props.formId, props.entryId, status))
	} catch {
		statusFailed.value = true
	} finally {
		statusBusy.value = false
	}
}

async function retry(delivery: Delivery): Promise<void> {
	retrying.value = delivery.id
	deliveriesFailed.value = false
	try {
		const updated = await retryDelivery(props.formId, delivery.id)
		deliveries.value = (deliveries.value ?? []).map((row) => (row.id === updated.id ? updated : row))
		emit('changed')
	} catch {
		deliveriesFailed.value = true
	} finally {
		retrying.value = null
	}
}

function print(): void {
	window.print()
}
</script>

<template>
	<div class="af-page">
		<nav class="af-page__nav af-noprint" :aria-label="t('webforms', 'Responses')">
			<NcButton variant="tertiary" :href="listHref">
				<template #icon>
					<Icon :path="icons.arrowLeft" />
				</template>
				{{ t('webforms', 'All responses') }}
			</NcButton>
			<span class="af-page__spacer" />
			<NcButton variant="secondary"
				:disabled="!detail || detail.previousId === null"
				:href="detail && detail.previousId !== null ? listHref + '/' + detail.previousId : undefined">
				{{ t('webforms', 'Earlier') }}
			</NcButton>
			<NcButton variant="secondary"
				:disabled="!detail || detail.nextId === null"
				:href="detail && detail.nextId !== null ? listHref + '/' + detail.nextId : undefined">
				{{ t('webforms', 'Later') }}
			</NcButton>
			<NcButton variant="secondary" :disabled="!detail" @click="print">
				<template #icon>
					<Icon :path="icons.print" />
				</template>
				{{ t('webforms', 'Print') }}
			</NcButton>
		</nav>

		<div v-if="loading && !detail" class="af-page__center">
			<NcLoadingIcon :size="44" />
		</div>
		<NcEmptyContent v-else-if="missing"
			:name="t('webforms', 'This response does not exist')"
			:description="t('webforms', 'It may have been deleted.')">
			<template #action>
				<NcButton variant="secondary" :href="listHref">
					{{ t('webforms', 'All responses') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<NcNoteCard v-else-if="failed" type="error">
			{{ t('webforms', 'Could not load the response.') }}
			<NcButton variant="tertiary" @click="load">
				{{ t('webforms', 'Try again') }}
			</NcButton>
		</NcNoteCard>

		<template v-else-if="detail">
			<article class="af-page__sheet">
				<header class="af-page__head">
					<p class="af-page__form">
						{{ title }}
					</p>
					<h3 class="af-page__title">
						{{ t('webforms', 'Response #{id}', { id: detail.entry.id }) }}
					</h3>
					<p class="af-page__when">
						{{ formatTime(detail.entry.submittedAt) }}
						<template v-if="detail.entry.userId">
							· {{ detail.entry.userId }}
						</template>
					</p>
				</header>
				<dl class="af-page__answers">
					<template v-for="line in lines" :key="line.id">
						<dt>{{ line.label }}</dt>
						<dd :class="{ 'af-page__none': !line.answered }">
							<ul v-if="line.files.length > 0" class="af-page__files">
								<li v-for="(file, index) in line.files" :key="index">
									<a :href="entryFileUrl(formId, detail.entry.id, line.id, index)" download>{{ file.name }}</a>
									<span class="af-page__size">{{ formatSize(file.size) }}</span>
								</li>
							</ul>
							<template v-else>
								{{ line.answered ? line.value : t('webforms', 'Not answered') }}
							</template>
						</dd>
					</template>
				</dl>
				<p v-if="lines.length === 0" class="af-page__none">
					{{ t('webforms', 'No answers') }}
				</p>
			</article>

			<aside class="af-page__side af-noprint">
				<section v-if="statuses.length > 0" class="af-page__block">
					<h4 class="af-page__heading">
						{{ t('webforms', 'Status') }}
					</h4>
					<StatusSelect :statuses="statuses"
						:model-value="detail.entry.status"
						:label="t('webforms', 'Status of response {id}', { id: detail.entry.id })"
						:disabled="statusBusy"
						@update:model-value="changeStatus" />
					<p v-if="statusFailed" class="af-page__error" role="alert">
						{{ t('webforms', 'Could not change the status.') }}
					</p>
				</section>

				<NoteEditor :form-id="formId" :entry="detail.entry" @saved="apply" />

				<section class="af-page__block">
					<h4 class="af-page__heading">
						{{ t('webforms', 'Emails and webhooks') }}
					</h4>
					<p v-if="deliveriesFailed" class="af-page__error" role="alert">
						{{ t('webforms', 'Could not load or update the deliveries.') }}
					</p>
					<NcLoadingIcon v-else-if="deliveries === null" :size="24" />
					<p v-else-if="deliveries.length === 0" class="af-page__muted">
						{{ t('webforms', 'Nothing was sent for this response.') }}
					</p>
					<ul v-if="deliveries && deliveries.length > 0" class="af-page__list">
						<DeliveryRow v-for="delivery in deliveries"
							:key="delivery.id"
							:delivery="delivery"
							:busy="retrying === delivery.id"
							@retry="retry(delivery)" />
					</ul>
				</section>
			</aside>
		</template>
	</div>
</template>

<style scoped>
.af-page {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
}

.af-page__nav {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.af-page__spacer {
	flex: 1 1 auto;
}

.af-page__center {
	display: flex;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 10) 0;
}

.af-page__sheet {
	padding: calc(var(--default-grid-baseline) * 5);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.af-page__head {
	margin-bottom: calc(var(--default-grid-baseline) * 5);
	padding-bottom: calc(var(--default-grid-baseline) * 3);
	border-bottom: 1px solid var(--color-border);
}

.af-page__form,
.af-page__when {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.af-page__title {
	margin: 2px 0;
	font-size: 22px;
	font-weight: bold;
	line-height: 1.3;
}

.af-page__answers {
	display: grid;
	grid-template-columns: minmax(8rem, 32%) minmax(0, 1fr);
	gap: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 5);
	margin: 0;
}

.af-page__answers dt {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.af-page__answers dd {
	margin: 0;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.af-page__none,
.af-page__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.af-page__none {
	font-style: italic;
}

.af-page__files {
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-page__size {
	margin-inline-start: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.af-page__side {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 5);
}

.af-page__block {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}

.af-page__heading {
	margin: 0;
	font-size: 14px;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.af-page__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	align-self: stretch;
	margin: 0;
	padding: 0;
}

.af-page__error {
	margin: 0;
	color: var(--color-error-text);
}

@media (max-width: 600px) {
	.af-page__answers {
		grid-template-columns: 1fr;
		gap: 0;
	}

	.af-page__answers dd {
		margin-bottom: calc(var(--default-grid-baseline) * 3);
	}
}

@media print {
	.af-page__sheet {
		padding: 0;
		border: 0;
	}

	.af-page__answers dt,
	.af-page__form,
	.af-page__when,
	.af-page__none {
		color: #444;
	}

	.af-page__answers dd,
	.af-page__title {
		color: #000;
	}

	.af-page__answers dt,
	.af-page__answers dd {
		break-inside: avoid;
	}
}
</style>

<!-- Printing a response shows the response and nothing of the app around it. Not scoped, because what
     has to go (Nextcloud's header, the forms list, the form's bar) is outside this component. -->
<style>
@media print {
	#header,
	.app-navigation,
	.app-navigation-toggle-wrapper,
	.af-bar,
	.af-noprint {
		display: none !important;
	}

	html,
	body,
	#content,
	#content-vue,
	.app-content,
	.af-entries {
		position: static !important;
		display: block !important;
		width: auto !important;
		height: auto !important;
		max-height: none !important;
		margin: 0 !important;
		overflow: visible !important;
		border-radius: 0 !important;
		background: #fff !important;
	}
}
</style>
