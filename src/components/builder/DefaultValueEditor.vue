<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { FormField } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { isBooleanType, isNumericType, isTemporalType, temporalInputType } from '../../rules/fieldTypes.ts'
import { computed } from 'vue'

// The answer a field starts with (docs/SCHEMA.md 4.7), with a control that suits its type. The server
// checks that the field accepts it when the form is saved and published.

const props = defineProps<{
	fieldId: string
	field: FormField
}>()

const emit = defineEmits<{
	/** The new default, or undefined for none. */
	change: [value: unknown]
}>()

const type = computed(() => props.field.type)
const value = computed(() => props.field.defaultValue)
const text = computed(() => (typeof value.value === 'string' || typeof value.value === 'number' ? String(value.value) : ''))
const picked = computed(() => (Array.isArray(value.value) ? value.value.filter((entry): entry is string => typeof entry === 'string') : []))
const inputId = computed(() => 'af-default-' + props.fieldId)

function setText(raw: string | number): void {
	const str = String(raw)
	emit('change', str.trim() === '' ? undefined : str)
}

function setNumber(raw: string | number): void {
	const str = String(raw).trim()
	const parsed = Number(str)
	emit('change', str === '' || Number.isNaN(parsed) ? undefined : parsed)
}

function toggleOption(option: string, on: boolean): void {
	const next = on ? [...picked.value, option] : picked.value.filter((entry) => entry !== option)
	// Keep the form's option order, whatever order they were ticked in.
	const ordered = (props.field.options ?? []).map((entry) => entry.value).filter((entry) => next.includes(entry))
	emit('change', ordered.length > 0 ? ordered : undefined)
}
</script>

<template>
	<div class="af-default">
		<NcCheckboxRadioSwitch v-if="isBooleanType(type)"
			type="switch"
			:model-value="value === true"
			@update:model-value="(on: boolean) => emit('change', on ? true : undefined)">
			{{ t('webforms', 'Ticked at the start') }}
		</NcCheckboxRadioSwitch>

		<template v-else-if="type === 'checkboxes'">
			<span class="af-default__caption">{{ t('webforms', 'Ticked at the start') }}</span>
			<NcCheckboxRadioSwitch v-for="option in field.options ?? []"
				:key="option.value"
				:model-value="picked.includes(option.value)"
				@update:model-value="(on: boolean) => toggleOption(option.value, on)">
				{{ option.label }}
			</NcCheckboxRadioSwitch>
		</template>

		<template v-else-if="type === 'select' || type === 'radio'">
			<label class="af-default__caption" :for="inputId">{{ t('webforms', 'Default value') }}</label>
			<select :id="inputId"
				class="af-default__native"
				:value="typeof value === 'string' ? value : ''"
				@change="emit('change', ($event.target as HTMLSelectElement).value || undefined)">
				<option value="">
					{{ t('webforms', 'None') }}
				</option>
				<option v-for="option in field.options ?? []" :key="option.value" :value="option.value">
					{{ option.label }}
				</option>
			</select>
		</template>

		<template v-else-if="isTemporalType(type)">
			<label class="af-default__caption" :for="inputId">{{ t('webforms', 'Default value') }}</label>
			<input :id="inputId"
				class="af-default__native"
				:type="temporalInputType(type)"
				:value="text"
				@change="setText(($event.target as HTMLInputElement).value)">
		</template>

		<NcTextArea v-else-if="type === 'textarea'"
			:model-value="text"
			:label="t('webforms', 'Default value')"
			rows="3"
			@update:model-value="setText" />

		<NcTextField v-else-if="isNumericType(type)"
			:model-value="text"
			type="number"
			:label="t('webforms', 'Default value')"
			@update:model-value="setNumber" />

		<NcTextField v-else
			:model-value="text"
			:label="t('webforms', 'Default value')"
			@update:model-value="setText" />
	</div>
</template>

<style scoped>
.af-default {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.af-default__caption {
	font-weight: bold;
}

.af-default__native {
	width: 100%;
	margin: 0;
}
</style>
