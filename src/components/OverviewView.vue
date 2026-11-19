<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { AttentionReason } from '../overview.ts'
import type { OwnerForm } from '../types.ts'

import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import { computed } from 'vue'
import { statusText } from '../availability.ts'
import { summarize } from '../overview.ts'

const props = defineProps<{
	forms: OwnerForm[]
	canCreate: boolean
	creating: boolean
}>()

defineEmits<{ create: [] }>()

const overview = computed(() => summarize(props.forms))

const tiles = computed(() => {
	const o = overview.value
	const list = [
		{ key: 'total', label: t('webforms', 'Forms'), value: o.total, highlight: false },
		{ key: 'published', label: t('webforms', 'Published'), value: o.published, highlight: false },
		{ key: 'drafts', label: t('webforms', 'Drafts'), value: o.drafts, highlight: false },
	]
	// Only shown when there is one, so the tiles add up without a zero that means nothing.
	if (o.closed > 0) {
		list.push({ key: 'closed', label: t('webforms', 'Closed'), value: o.closed, highlight: false })
	}
	list.push({ key: 'new', label: t('webforms', 'New responses'), value: o.newResponses, highlight: o.newResponses > 0 })
	return list
})

function reasonText(reason: AttentionReason, form: OwnerForm): string {
	switch (reason) {
		case 'new': return n('webforms', '%n new response', '%n new responses', form.newCount ?? 0)
		case 'full': return t('webforms', 'Full: it takes no more responses')
		case 'ended': return t('webforms', 'Ended: past its closing date')
		case 'unpublished': return t('webforms', 'Has unpublished changes')
	}
}

/** Responses answer "new", "full" and "ended". Unpublished changes are for the editor. */
function hrefFor(form: OwnerForm, reasons: AttentionReason[]): string {
	const editing = reasons.length === 1 && reasons[0] === 'unpublished'
	return '#/f/' + form.id + (editing ? '' : '/responses')
}

function editedText(form: OwnerForm): string {
	return new Date(form.updatedAt * 1000).toLocaleDateString(undefined, { dateStyle: 'medium' })
}
</script>

<template>
	<div class="af-overview">
		<h2 class="af-overview__title">
			{{ t('webforms', 'Overview') }}
		</h2>

		<ul class="af-overview__tiles" :aria-label="t('webforms', 'Totals')">
			<li v-for="tile in tiles"
				:key="tile.key"
				class="af-tile"
				:class="{ 'af-tile--highlight': tile.highlight }">
				<span class="af-tile__value">{{ tile.value }}</span>
				<span class="af-tile__label">{{ tile.label }}</span>
			</li>
		</ul>

		<section class="af-overview__section" aria-labelledby="af-overview-attention">
			<h3 id="af-overview-attention">
				{{ t('webforms', 'Needs attention') }}
			</h3>
			<p v-if="overview.attention.length === 0" class="af-overview__quiet">
				{{ t('webforms', 'Nothing needs you right now.') }}
			</p>
			<ul v-else class="af-overview__list">
				<li v-for="item in overview.attention" :key="item.form.id">
					<a :href="hrefFor(item.form, item.reasons)" class="af-row">
						<span class="af-row__name">{{ item.form.title }}</span>
						<span class="af-row__reasons">
							<span v-for="reason in item.reasons" :key="reason" class="af-row__reason">
								{{ reasonText(reason, item.form) }}
							</span>
						</span>
						<NcCounterBubble v-if="(item.form.newCount ?? 0) > 0"
							:count="item.form.newCount ?? 0"
							type="highlighted"
							aria-hidden="true" />
					</a>
				</li>
			</ul>
		</section>

		<section v-if="overview.recent.length > 0" class="af-overview__section" aria-labelledby="af-overview-recent">
			<h3 id="af-overview-recent">
				{{ t('webforms', 'Recently edited') }}
			</h3>
			<ul class="af-overview__list">
				<li v-for="form in overview.recent" :key="form.id">
					<a :href="'#/f/' + form.id" class="af-row">
						<span class="af-row__name">{{ form.title }}</span>
						<span class="af-row__reasons">
							<span class="af-row__reason">{{ statusText(form) }}</span>
						</span>
						<span class="af-row__date">{{ editedText(form) }}</span>
					</a>
				</li>
			</ul>
		</section>

		<NcButton variant="primary"
			class="af-overview__new"
			:disabled="creating || !canCreate"
			@click="$emit('create')">
			{{ t('webforms', 'New form') }}
		</NcButton>
	</div>
</template>

<style scoped>
.af-overview {
	box-sizing: border-box;
	width: 100%;
	max-width: 760px;
	max-height: 100%;
	margin: 0 auto;
	padding: 24px 16px;
	overflow-y: auto;
	/* The home area centres the empty state; the overview starts at the top instead. */
	align-self: flex-start;
}

.af-overview__title {
	margin: 0 0 16px;
	font-size: 24px;
}

.af-overview__tiles,
.af-overview__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-overview__tiles {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 12px;
	margin-bottom: 24px;
}

.af-tile {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.af-tile--highlight {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.af-tile__value {
	font-size: 28px;
	font-weight: bold;
	line-height: 1.1;
}

.af-tile__label {
	color: var(--color-text-maxcontrast);
}

.af-overview__section {
	margin-bottom: 24px;
}

.af-overview__section h3 {
	margin: 0 0 8px;
	font-size: 16px;
}

.af-overview__quiet {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.af-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 12px;
	border-radius: var(--border-radius-large);
	color: inherit;
}

.af-row:hover,
.af-row:focus-visible {
	background: var(--color-background-hover);
}

.af-row__name {
	flex: 0 1 auto;
	min-width: 0;
	overflow: hidden;
	font-weight: bold;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.af-row__reasons {
	display: flex;
	flex: 1 1 auto;
	flex-wrap: wrap;
	gap: 4px 12px;
	min-width: 0;
	color: var(--color-text-maxcontrast);
}

.af-row__date {
	flex: none;
	color: var(--color-text-maxcontrast);
}

.af-overview__new {
	margin-top: 8px;
}
</style>
