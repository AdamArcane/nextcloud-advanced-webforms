<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts" generic="T extends string | number">
export interface SegmentOption<V> {
	value: V
	label: string
	disabled?: boolean
	title?: string
}

defineProps<{
	options: SegmentOption<T>[]
	modelValue: T | null
	groupLabel: string
}>()

const emit = defineEmits<{
	'update:modelValue': [value: T]
}>()
</script>

<template>
	<div class="af-seg" role="radiogroup" :aria-label="groupLabel">
		<button v-for="option in options"
			:key="String(option.value)"
			type="button"
			role="radio"
			class="af-seg__item"
			:class="{ 'af-seg__item--on': option.value === modelValue }"
			:aria-checked="option.value === modelValue"
			:disabled="option.disabled"
			:title="option.title"
			@click="emit('update:modelValue', option.value)">
			{{ option.label }}
		</button>
	</div>
</template>

<style scoped>
.af-seg {
	display: flex;
	gap: 2px;
	padding: 2px;
	border-radius: var(--border-radius-element);
	background: var(--color-background-dark);
}

.af-seg__item {
	flex: 1 1 0;
	min-width: 0;
	min-height: calc(var(--default-clickable-area) - 6px);
	padding: 0 var(--default-grid-baseline);
	border: 0;
	border-radius: calc(var(--border-radius-element) - 2px);
	background: transparent;
	color: var(--color-main-text);
	font: inherit;
	cursor: pointer;
	transition: background-color 0.12s ease, color 0.12s ease;
}

.af-seg__item:hover:not(:disabled, .af-seg__item--on) {
	background: var(--color-background-hover);
}

.af-seg__item--on {
	background: var(--color-main-background);
	box-shadow: 0 0 0 1px var(--color-border);
	font-weight: bold;
}

.af-seg__item:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: -2px;
}

.af-seg__item:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}
</style>
