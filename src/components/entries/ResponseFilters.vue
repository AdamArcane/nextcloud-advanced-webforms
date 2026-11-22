<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { EntryFilterState, Respondent } from '../../entries/filter.ts'
import type { ResponseStatus } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { isFilterActive, NO_STATUS, rangeInvalid } from '../../entries/filter.ts'
import { statusLabel } from '../../entries/statuses.ts'
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'

const props = defineProps<{
	modelValue: EntryFilterState
	/** The statuses a response can be given. None hides the status filter. */
	statuses?: readonly ResponseStatus[]
	/** The form keeps a waiting list, so the filter for it is offered. */
	waitlist?: boolean
}>()

const emit = defineEmits<{
	'update:modelValue': [filter: EntryFilterState]
}>()

const DEBOUNCE_MS = 300

const text = ref(props.modelValue.text)
let timer: ReturnType<typeof setTimeout> | null = null

// Typing waits for a pause before it asks the server, everything else applies at once.
watch(text, (value) => {
	if (timer !== null) {
		clearTimeout(timer)
	}
	timer = setTimeout(() => update({ text: value }), DEBOUNCE_MS)
})
watch(() => props.modelValue.text, (value) => {
	if (value !== text.value) {
		text.value = value
	}
})
onBeforeUnmount(() => {
	if (timer !== null) {
		clearTimeout(timer)
	}
})

const active = computed(() => isFilterActive(props.modelValue))
const badRange = computed(() => rangeInvalid(props.modelValue))

const RESPONDENTS: { value: Respondent, label: string }[] = [
	{ value: 'any', label: t('webforms', 'Anyone') },
	{ value: 'signed_in', label: t('webforms', 'Signed in') },
	{ value: 'anonymous', label: t('webforms', 'Anonymous') },
]

function update(changes: Partial<EntryFilterState>): void {
	emit('update:modelValue', { ...props.modelValue, ...changes })
}

function clear(): void {
	text.value = ''
	emit('update:modelValue', { text: '', from: '', to: '', respondent: 'any', where: null, statuses: [], sort: props.modelValue.sort })
}
</script>

<template>
	<div class="af-filters">
		<div class="af-filters__row">
			<NcTextField v-model="text"
				class="af-filters__search"
				:label="t('webforms', 'Search responses')"
				type="search"
				trailing-button-icon="close"
				:show-trailing-button="text !== ''"
				@trailing-button-click="text = ''">
				<template #icon>
					<Icon :path="icons.search" :size="18" />
				</template>
			</NcTextField>

			<label class="af-filters__field">
				<span>{{ t('webforms', 'From') }}</span>
				<input type="date"
					:value="modelValue.from"
					:max="modelValue.to || undefined"
					@change="update({ from: ($event.target as HTMLInputElement).value })">
			</label>
			<label class="af-filters__field">
				<span>{{ t('webforms', 'To') }}</span>
				<input type="date"
					:value="modelValue.to"
					:min="modelValue.from || undefined"
					@change="update({ to: ($event.target as HTMLInputElement).value })">
			</label>

			<label class="af-filters__field">
				<span>{{ t('webforms', 'Sent by') }}</span>
				<select :value="modelValue.respondent" @change="update({ respondent: ($event.target as HTMLSelectElement).value as Respondent })">
					<option v-for="option in RESPONDENTS" :key="option.value" :value="option.value">
						{{ option.label }}
					</option>
				</select>
			</label>

			<label v-if="statuses && statuses.length > 0" class="af-filters__field">
				<span>{{ t('webforms', 'Status') }}</span>
				<select :value="modelValue.statuses[0] ?? ''" @change="update({ statuses: ($event.target as HTMLSelectElement).value === '' ? [] : [($event.target as HTMLSelectElement).value] })">
					<option value="">
						{{ t('webforms', 'Any') }}
					</option>
					<option :value="NO_STATUS">
						{{ t('webforms', 'No status') }}
					</option>
					<option v-for="status in statuses" :key="status.id" :value="status.id">
						{{ statusLabel(status) }}
					</option>
				</select>
			</label>

			<label v-if="waitlist || modelValue.waitlisted" class="af-filters__field">
				<span>{{ t('webforms', 'Waiting list') }}</span>
				<select :value="modelValue.waitlisted === true ? 'only' : ''" @change="update({ waitlisted: ($event.target as HTMLSelectElement).value === 'only' ? true : undefined })">
					<option value="">
						{{ t('webforms', 'All responses') }}
					</option>
					<option value="only">
						{{ t('webforms', 'Only the waiting list') }}
					</option>
				</select>
			</label>

			<NcButton v-if="active" variant="tertiary" @click="clear">
				{{ t('webforms', 'Clear filters') }}
			</NcButton>
		</div>

		<p v-if="badRange" class="af-filters__error">
			{{ t('webforms', 'The end date is before the start date.') }}
		</p>
	</div>
</template>

<style scoped>
.af-filters {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.af-filters__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.af-filters__search {
	flex: 1 1 220px;
	min-width: 0;
}

.af-filters__field {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.af-filters__field input,
.af-filters__field select {
	min-height: var(--default-clickable-area);
	margin: 0;
}

.af-filters__error {
	margin: 0;
	color: var(--color-error-text);
	font-size: 13px;
}
</style>
