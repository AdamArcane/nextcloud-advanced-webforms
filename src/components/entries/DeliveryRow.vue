<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Delivery } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { canRetry, describeDeliveryError, statusLabel, typeLabel } from '../../entries/deliveryText.ts'
import { formatTime, inFuture } from '../../entries/format.ts'

defineProps<{
	delivery: Delivery
	/** Show which response it belongs to, with a link to it. */
	showEntry?: boolean
	busy?: boolean
}>()

const emit = defineEmits<{
	retry: []
	openEntry: []
}>()
</script>

<template>
	<li class="af-delivery" :class="'af-delivery--' + delivery.status">
		<div class="af-delivery__main">
			<div class="af-delivery__line">
				<span class="af-delivery__type">{{ typeLabel(delivery.type) }}</span>
				<span v-if="delivery.target" class="af-delivery__target">{{ delivery.target }}</span>
				<span class="af-delivery__status">{{ statusLabel(delivery.status) }}</span>
			</div>
			<p v-if="delivery.error" class="af-delivery__error">
				{{ describeDeliveryError(delivery.error) }}
			</p>
			<p class="af-delivery__meta">
				<template v-if="showEntry">
					<button type="button" class="af-delivery__link" @click="emit('openEntry')">
						{{ t('advancedforms', 'Response #{id}', { id: delivery.entryId }) }}
					</button>
					·
				</template>
				{{ formatTime(delivery.updatedAt ?? delivery.createdAt) }}
				<template v-if="delivery.attempt > 0">
					· {{ t('advancedforms', 'Attempt {n}', { n: delivery.attempt }) }}
				</template>
				<template v-if="delivery.status === 'queued' && delivery.nextAttemptAt">
					· {{ t('advancedforms', 'next try {when}', { when: inFuture(delivery.nextAttemptAt) }) }}
				</template>
			</p>
		</div>
		<NcButton v-if="canRetry(delivery)"
			variant="secondary"
			:disabled="busy"
			@click="emit('retry')">
			{{ t('advancedforms', 'Try again') }}
		</NcButton>
	</li>
</template>

<style scoped>
.af-delivery {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-inline-start-width: 4px;
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	list-style: none;
}

.af-delivery--success {
	border-inline-start-color: var(--color-success);
}

.af-delivery--failed {
	border-inline-start-color: var(--color-error);
}

.af-delivery--queued {
	border-inline-start-color: var(--color-primary-element);
}

.af-delivery--skipped {
	border-inline-start-color: var(--color-border-maxcontrast);
}

.af-delivery__main {
	min-width: 0;
}

.af-delivery__line {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
}

.af-delivery__type {
	font-weight: bold;
}

.af-delivery__target {
	overflow-wrap: anywhere;
	color: var(--color-text-maxcontrast);
}

.af-delivery__status {
	padding: 0 calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-size: 12px;
	line-height: 22px;
}

.af-delivery--failed .af-delivery__status {
	background: var(--color-error);
	color: var(--color-error-text);
}

.af-delivery--success .af-delivery__status {
	background: var(--color-success);
	color: var(--color-success-text);
}

.af-delivery__error {
	margin: var(--default-grid-baseline) 0 0;
	color: var(--color-error-text);
}

.af-delivery__meta {
	margin: var(--default-grid-baseline) 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.af-delivery__link {
	padding: 0;
	border: 0;
	background: none;
	color: var(--color-main-text);
	font: inherit;
	text-decoration: underline;
	cursor: pointer;
}

.af-delivery__link:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}
</style>
