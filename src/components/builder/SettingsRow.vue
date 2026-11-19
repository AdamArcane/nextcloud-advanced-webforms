<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
/**
 * One setting in a card: its label on the left and its control on the right, with a hint under the
 * control. Without a label the control takes the whole width (a text field has its own label).
 * Rows are separated by a thin line. On a narrow dialog the label goes above the control.
 */
defineProps<{
	label?: string
	hint?: string
}>()
</script>

<template>
	<div class="af-row" :class="{ 'af-row--wide': !label }">
		<div v-if="label" class="af-row__label">
			{{ label }}
		</div>
		<div class="af-row__control">
			<slot />
			<p v-if="hint" class="af-row__hint">
				{{ hint }}
			</p>
		</div>
	</div>
</template>

<style scoped>
.af-row {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 3) 0;
}

.af-row + .af-row {
	border-top: 1px solid var(--color-border);
}

.af-row:last-child {
	padding-bottom: 0;
}

.af-row:first-child {
	padding-top: 0;
}

.af-row__label {
	padding-top: calc(var(--default-grid-baseline) * 1.5);
	font-weight: 500;
	overflow-wrap: anywhere;
}

.af-row__control {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 2);
	min-width: 0;
}

.af-row__control > :deep(.input-field),
.af-row__control > :deep(.textarea),
.af-row__control > :deep(.af-seg) {
	width: 100%;
}

.af-row__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	overflow-wrap: anywhere;
}

@container (min-width: 560px) {
	.af-row:not(.af-row--wide) {
		display: grid;
		grid-template-columns: minmax(120px, 190px) minmax(0, 1fr);
		column-gap: calc(var(--default-grid-baseline) * 6);
		align-items: start;
	}
}
</style>
