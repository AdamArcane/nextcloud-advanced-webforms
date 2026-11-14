<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Delivery, EntryRecord, VersionDefinition } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { computed, ref, watch } from 'vue'
import { listDeliveries, retryDelivery } from '../../api/forms.ts'
import { answerLines, formatTime, previewLines } from '../../entries/format.ts'
import DeliveryRow from './DeliveryRow.vue'

const props = defineProps<{
	formId: number
	entry: EntryRecord
	definition: VersionDefinition
	expanded: boolean
}>()

const emit = defineEmits<{
	toggle: []
	delete: []
	/** A delivery was retried, so the totals elsewhere are out of date. */
	changed: []
}>()

const lines = computed(() => answerLines(props.definition, props.entry.data))
const preview = computed(() => previewLines(props.definition, props.entry.data))

const deliveries = ref<Delivery[] | null>(null)
const loading = ref(false)
const failed = ref(false)
const retrying = ref<number | null>(null)

// Deliveries are fetched the first time a response is opened, not for the whole list up front.
watch(() => props.expanded, async (open) => {
	if (!open || deliveries.value !== null || loading.value) {
		return
	}
	loading.value = true
	failed.value = false
	try {
		deliveries.value = (await listDeliveries(props.formId, { entryId: props.entry.id, limit: 50 })).rows
	} catch {
		failed.value = true
	} finally {
		loading.value = false
	}
}, { immediate: true })

async function retry(delivery: Delivery): Promise<void> {
	retrying.value = delivery.id
	failed.value = false
	try {
		const updated = await retryDelivery(props.formId, delivery.id)
		deliveries.value = (deliveries.value ?? []).map((row) => (row.id === updated.id ? updated : row))
		emit('changed')
	} catch {
		failed.value = true
	} finally {
		retrying.value = null
	}
}
</script>

<template>
	<article class="af-response" :class="{ 'af-response--open': expanded }">
		<button type="button"
			class="af-response__head"
			:aria-expanded="expanded"
			@click="emit('toggle')">
			<span class="af-response__when">{{ formatTime(entry.submittedAt) }}</span>
			<span v-if="entry.userId" class="af-response__who">{{ entry.userId }}</span>
			<span class="af-response__preview">
				<template v-if="preview.length > 0">
					<span v-for="line in preview" :key="line.id" class="af-response__bit"><strong>{{ line.label }}:</strong> {{ line.value }}</span>
				</template>
				<span v-else class="af-response__empty">{{ t('advancedforms', 'No answers') }}</span>
			</span>
			<span class="af-response__chevron" :class="{ 'af-response__chevron--open': expanded }" aria-hidden="true" />
		</button>

		<div v-if="expanded" class="af-response__body">
			<dl class="af-response__answers">
				<template v-for="line in lines" :key="line.id">
					<dt>{{ line.label }}</dt>
					<dd :class="{ 'af-response__none': !line.answered }">
						{{ line.answered ? line.value : '—' }}
					</dd>
				</template>
			</dl>

			<section class="af-response__deliveries">
				<h4 class="af-response__heading">
					{{ t('advancedforms', 'Emails and webhooks') }}
				</h4>
				<NcLoadingIcon v-if="loading" :size="24" />
				<p v-if="failed" class="af-response__note" role="alert">
					{{ t('advancedforms', 'Could not load or update the deliveries.') }}
				</p>
				<p v-else-if="deliveries && deliveries.length === 0" class="af-response__note">
					{{ t('advancedforms', 'Nothing was sent for this response.') }}
				</p>
				<ul v-if="deliveries && deliveries.length > 0" class="af-response__list">
					<DeliveryRow v-for="delivery in deliveries"
						:key="delivery.id"
						:delivery="delivery"
						:busy="retrying === delivery.id"
						@retry="retry(delivery)" />
				</ul>
			</section>

			<div class="af-response__footer">
				<span class="af-response__id">{{ t('advancedforms', 'Response #{id}', { id: entry.id }) }}</span>
				<NcButton variant="error" @click="emit('delete')">
					{{ t('advancedforms', 'Delete response') }}
				</NcButton>
			</div>
		</div>
	</article>
</template>

<style scoped>
.af-response {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.af-response__head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 3);
	position: relative;
	width: 100%;
	box-sizing: border-box;
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 9) calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4);
	border: 0;
	border-radius: var(--border-radius-large);
	background: transparent;
	color: var(--color-main-text);
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.af-response__head:hover {
	background: var(--color-background-hover);
}

.af-response__head:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: -2px;
}

.af-response__when {
	font-weight: bold;
	white-space: nowrap;
}

.af-response__who {
	color: var(--color-text-maxcontrast);
}

.af-response__preview {
	display: flex;
	flex: 1 1 14rem;
	flex-wrap: wrap;
	gap: 0 calc(var(--default-grid-baseline) * 3);
	min-width: 0;
	color: var(--color-text-maxcontrast);
}

.af-response__bit {
	overflow: hidden;
	max-width: 100%;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.af-response__empty {
	font-style: italic;
}

.af-response__chevron {
	position: absolute;
	inset-inline-end: calc(var(--default-grid-baseline) * 5);
	top: calc(var(--default-grid-baseline) * 5);
	width: 8px;
	height: 8px;
	border-right: 2px solid var(--color-text-maxcontrast);
	border-bottom: 2px solid var(--color-text-maxcontrast);
	transform: rotate(45deg);
	transition: transform 0.15s ease;
}

.af-response__chevron--open {
	transform: rotate(225deg);
}

.af-response__body {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	padding: 0 calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 4);
}

.af-response__answers {
	display: grid;
	grid-template-columns: minmax(7rem, 30%) minmax(0, 1fr);
	gap: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
	margin: 0;
}

.af-response__answers dt {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.af-response__answers dd {
	margin: 0;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.af-response__none {
	color: var(--color-text-maxcontrast);
}

@media (max-width: 600px) {
	.af-response__answers {
		grid-template-columns: 1fr;
		gap: 0;
	}

	.af-response__answers dd {
		margin-bottom: calc(var(--default-grid-baseline) * 2);
	}
}

.af-response__heading {
	margin: 0 0 var(--default-grid-baseline);
	font-size: 14px;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.af-response__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin: 0;
	padding: 0;
}

.af-response__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.af-response__footer {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.af-response__id {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
