<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { FormField } from '../../types.ts'

import { computed } from 'vue'
import { partAutocomplete, partLabel } from '../../partLabels.ts'
import { partsOf } from '../../rules/fieldTypes.ts'

// A name or an address, asked in parts (docs/SCHEMA.md 4.10). Each part is a labelled input that the
// browser can fill in by itself. The answer is an object from part to text.

const props = defineProps<{
	fieldId: string
	field: FormField
	modelValue: unknown
	describedBy?: string
	invalid?: boolean
}>()

const emit = defineEmits<{
	'update:modelValue': [value: Record<string, unknown>]
}>()

const parts = computed(() => partsOf(props.field))
const answers = computed<Record<string, unknown>>(() => (typeof props.modelValue === 'object' && props.modelValue !== null && !Array.isArray(props.modelValue) ? props.modelValue as Record<string, unknown> : {}))
const wide = (part: string) => part === 'street' || part === 'street2'

function set(part: string, event: Event) {
	emit('update:modelValue', { ...answers.value, [part]: (event.target as HTMLInputElement).value })
}
</script>

<template>
	<div class="af-parts" :class="'af-parts--' + field.type">
		<label v-for="(part, index) in parts"
			:key="part"
			class="af-parts__part"
			:class="{ 'af-parts__part--wide': wide(part) }">
			<span class="af-parts__label">{{ partLabel(part) }}</span>
			<input :id="index === 0 ? 'af-' + fieldId : undefined"
				type="text"
				maxlength="200"
				:autocomplete="partAutocomplete(part)"
				:value="typeof answers[part] === 'string' ? answers[part] as string : ''"
				:aria-invalid="invalid ? true : undefined"
				:aria-describedby="describedBy"
				@input="set(part, $event)">
		</label>
	</div>
</template>

<style scoped>
.af-parts {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(min(10rem, 100%), 1fr));
	gap: 8px 16px;
}

.af-parts__part {
	display: block;
	min-width: 0;
}

.af-parts__part--wide {
	grid-column: 1 / -1;
}

.af-parts__label {
	display: block;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.af-parts input {
	width: 100%;
	box-sizing: border-box;
}
</style>
