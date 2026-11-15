<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { EntrySummaryData, SummaryField } from '../../entries/summary.ts'

import { n, t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, ref, watch } from 'vue'
import { barRows, bucketLabel, columnHeights, formatNumber } from '../../entries/summary.ts'

const props = defineProps<{
	summary: EntrySummaryData | null
	loading: boolean
	failed: boolean
}>()

const buckets = computed(() => props.summary?.timeline.buckets ?? [])
const unit = computed(() => props.summary?.timeline.unit ?? 'day')
const heights = computed(() => columnHeights(buckets.value))
const peak = computed(() => Math.max(0, ...buckets.value.map((bucket) => bucket.count)))

/** The column under the pointer or the keyboard, whose value the line below the chart reads out. */
const active = ref<number | null>(null)
watch(buckets, () => {
	active.value = null
})

const readout = computed(() => {
	const bucket = active.value === null ? undefined : buckets.value[active.value]
	if (!bucket) {
		return null
	}
	return { count: bucket.count, label: bucketLabel(bucket, unit.value) }
})

function move(by: number): void {
	if (buckets.value.length === 0) {
		return
	}
	const next = active.value === null ? (by > 0 ? 0 : buckets.value.length - 1) : active.value + by
	active.value = Math.min(buckets.value.length - 1, Math.max(0, next))
}

function isBars(field: SummaryField): field is Exclude<SummaryField, { kind: 'number' }> {
	return field.kind !== 'number'
}
</script>

<template>
	<section class="af-summary" :aria-label="t('webforms', 'Summary of responses')">
		<NcNoteCard v-if="failed" type="error">
			{{ t('webforms', 'Could not load the summary.') }}
		</NcNoteCard>
		<div v-else-if="loading && !summary" class="af-summary__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-else-if="summary">
			<NcNoteCard v-if="summary.truncated" type="info">
				{{ t('webforms', 'This summary covers the first 20,000 responses. Narrow the dates to summarise the rest.') }}
			</NcNoteCard>

			<!-- Responses over time -->
			<div v-if="buckets.length > 0" class="af-summary__card af-summary__card--wide">
				<h3 class="af-summary__title">
					{{ unit === 'month' ? t('webforms', 'Responses per month') : t('webforms', 'Responses per day') }}
				</h3>
				<p class="af-summary__sub">
					{{ n('webforms', '{count} response', '{count} responses', summary.total, { count: summary.total }) }}
					· {{ unit === 'month' ? t('webforms', 'busiest month: {n}', { n: peak }) : t('webforms', 'busiest day: {n}', { n: peak }) }}
				</p>
				<div class="af-timeline"
					tabindex="0"
					role="group"
					:aria-label="t('webforms', 'Responses over time. Use the left and right arrow keys to read each column.')"
					@keydown.left.prevent="move(-1)"
					@keydown.right.prevent="move(1)"
					@blur="active = null"
					@pointerleave="active = null">
					<div v-for="(bucket, index) in buckets"
						:key="bucket.start"
						class="af-timeline__slot"
						:class="{ 'af-timeline__slot--active': active === index }"
						@pointerenter="active = index"
						@pointerdown="active = index">
						<div class="af-timeline__bar" :style="{ height: heights[index] + '%' }" />
					</div>
				</div>
				<div class="af-timeline__axis" aria-hidden="true">
					<span>{{ bucketLabel(buckets[0], unit) }}</span>
					<span v-if="buckets.length > 1">{{ bucketLabel(buckets[buckets.length - 1], unit) }}</span>
				</div>
				<p class="af-timeline__readout" aria-live="polite">
					<template v-if="readout">
						<strong>{{ readout.count }}</strong> · {{ readout.label }}
					</template>
					<template v-else>
						{{ t('webforms', 'Point at a column, or use the arrow keys, to read it. Dates are in UTC.') }}
					</template>
				</p>
				<table class="af-summary__sr">
					<caption>{{ t('webforms', 'Responses over time') }}</caption>
					<thead>
						<tr>
							<th scope="col">
								{{ t('webforms', 'Date') }}
							</th>
							<th scope="col">
								{{ t('webforms', 'Responses') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="bucket in buckets" :key="bucket.start">
							<td>{{ bucketLabel(bucket, unit) }}</td>
							<td>{{ bucket.count }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- One card per question that can be counted -->
			<div v-for="field in summary.fields" :key="field.id" class="af-summary__card">
				<h3 class="af-summary__title">
					{{ field.label }}
				</h3>
				<p class="af-summary__sub">
					{{ n('webforms', '{count} answer', '{count} answers', field.answered, { count: field.answered }) }}
					<template v-if="field.type === 'checkboxes'">
						· {{ t('webforms', 'people could pick several') }}
					</template>
				</p>

				<ul v-if="isBars(field)" class="af-bars">
					<li v-for="row in barRows(field)" :key="row.key" class="af-bars__row">
						<span class="af-bars__label" :title="row.label">{{ row.label }}</span>
						<span class="af-bars__track" aria-hidden="true">
							<span v-if="row.count > 0" class="af-bars__bar" :style="{ width: row.width + '%' }" />
						</span>
						<span class="af-bars__value"><strong>{{ row.count }}</strong> <span class="af-bars__percent">{{ row.percent }}%</span></span>
					</li>
				</ul>

				<dl v-else class="af-stats">
					<div class="af-stats__tile af-stats__tile--lead">
						<dt>{{ t('webforms', 'Average') }}</dt>
						<dd>{{ formatNumber(field.average) }}</dd>
					</div>
					<div class="af-stats__tile">
						<dt>{{ t('webforms', 'Lowest') }}</dt>
						<dd>{{ formatNumber(field.min) }}</dd>
					</div>
					<div class="af-stats__tile">
						<dt>{{ t('webforms', 'Highest') }}</dt>
						<dd>{{ formatNumber(field.max) }}</dd>
					</div>
					<div class="af-stats__tile">
						<dt>{{ t('webforms', 'Total') }}</dt>
						<dd>{{ formatNumber(field.sum) }}</dd>
					</div>
				</dl>
			</div>

			<p v-if="summary.fields.length === 0 && summary.total > 0" class="af-summary__sub">
				{{ t('webforms', 'This form has no choice, yes/no or number questions to count.') }}
			</p>
		</template>
	</section>
</template>

<style scoped>
.af-summary {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr));
	gap: calc(var(--default-grid-baseline) * 3);
}

.af-summary__loading {
	display: flex;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 4);
}

.af-summary__card {
	min-width: 0;
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.af-summary__card--wide,
.af-summary > :deep(.notecard) {
	grid-column: 1 / -1;
}

.af-summary__title {
	margin: 0;
	font-size: 15px;
	font-weight: bold;
	overflow-wrap: anywhere;
}

.af-summary__sub {
	margin: 0 0 calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

/* Screen readers get the numbers as a table; the chart itself is a picture of them. */
.af-summary__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

/* Columns: one hue, thin (never wider than 24px), rounded at the data end, square on the baseline. */
.af-timeline {
	display: flex;
	align-items: flex-end;
	gap: 2px;
	height: 96px;
	border-bottom: 1px solid var(--color-border);
	border-radius: 2px;
}

.af-timeline:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.af-timeline__slot {
	display: flex;
	flex: 1 1 0;
	align-items: flex-end;
	justify-content: center;
	min-width: 0;
	height: 100%;
}

.af-timeline__bar {
	width: 100%;
	max-width: 24px;
	border-radius: 4px 4px 0 0;
	background: var(--color-primary-element);
	opacity: 0.75;
}

.af-timeline__slot--active {
	background: var(--color-background-hover);
}

.af-timeline__slot--active .af-timeline__bar {
	opacity: 1;
}

.af-timeline__axis {
	display: flex;
	justify-content: space-between;
	margin-top: var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.af-timeline__readout {
	min-height: 1.5em;
	margin: var(--default-grid-baseline) 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.af-timeline__readout strong {
	color: var(--color-main-text);
}

/* Horizontal bars: label, bar, then the value at the tip in text colour, never the bar's colour. */
.af-bars {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-bars__row {
	display: grid;
	grid-template-columns: minmax(0, 40%) minmax(0, 1fr) auto;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: center;
}

.af-bars__label {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.af-bars__track {
	display: block;
	height: 12px;
}

.af-bars__bar {
	display: block;
	min-width: 2px;
	height: 100%;
	border-radius: 0 4px 4px 0;
	background: var(--color-primary-element);
}

.af-bars__value {
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.af-bars__percent {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

/* Numbers are not a chart: the average leads, the rest follow. */
.af-stats {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
	margin: 0;
}

.af-stats__tile--lead {
	grid-column: 1 / -1;
}

.af-stats dt {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.af-stats dd {
	margin: 0;
	font-size: 18px;
	font-variant-numeric: tabular-nums;
}

.af-stats__tile--lead dd {
	font-size: 32px;
	font-weight: bold;
	line-height: 1.1;
}
</style>
