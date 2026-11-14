<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { FieldOption } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { randomId } from '../../builder/definition.ts'

const props = defineProps<{
	options: FieldOption[]
}>()

const emit = defineEmits<{
	'update:options': [options: FieldOption[]]
}>()

function commit(next: FieldOption[]): void {
	emit('update:options', next)
}

function rename(index: number, label: string): void {
	commit(props.options.map((option, i) => (i === index ? { ...option, label } : option)))
}

function add(): void {
	// The value is the option's identity (rules compare it), so it is generated once and never edited.
	const value = randomId('opt', new Set(props.options.map((option) => option.value)))
	commit([...props.options, { value, label: t('advancedforms', 'Option {n}', { n: props.options.length + 1 }) }])
}

function remove(index: number): void {
	commit(props.options.filter((_, i) => i !== index))
}

function move(index: number, by: -1 | 1): void {
	const next = [...props.options]
	const target = index + by
	if (target < 0 || target >= next.length) {
		return
	}
	;[next[index], next[target]] = [next[target], next[index]]
	commit(next)
}
</script>

<template>
	<div class="af-options">
		<div v-for="(option, index) in options" :key="option.value" class="af-options__row">
			<NcTextField class="af-options__label"
				:model-value="option.label"
				:label="t('advancedforms', 'Option {n}', { n: index + 1 })"
				label-outside
				:aria-label="t('advancedforms', 'Label of option {n}', { n: index + 1 })"
				@update:model-value="(value: string | number) => rename(index, String(value))" />
			<NcButton variant="tertiary"
				:disabled="index === 0"
				:aria-label="t('advancedforms', 'Move option up')"
				@click="move(index, -1)">
				↑
			</NcButton>
			<NcButton variant="tertiary"
				:disabled="index === options.length - 1"
				:aria-label="t('advancedforms', 'Move option down')"
				@click="move(index, 1)">
				↓
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('advancedforms', 'Remove option')"
				@click="remove(index)">
				✕
			</NcButton>
		</div>
		<NcButton variant="secondary" class="af-options__add" @click="add">
			{{ t('advancedforms', 'Add option') }}
		</NcButton>
	</div>
</template>

<style scoped>
.af-options {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.af-options__row {
	display: flex;
	align-items: center;
	gap: 2px;
}

.af-options__label {
	flex: 1 1 auto;
	min-width: 0;
}

.af-options__add {
	align-self: flex-start;
	margin-top: var(--default-grid-baseline);
}
</style>
