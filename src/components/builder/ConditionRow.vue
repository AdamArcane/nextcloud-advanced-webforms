<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition } from '../../types.ts'
import type { LeafCondition } from '../../rules/types.ts'
import type { SourceField } from '../../builder/rules.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed } from 'vue'
import { operatorLabel } from '../../builder/labels.ts'
import { changeLeafField, changeLeafOp, valueKind } from '../../builder/rules.ts'
import { operatorsFor } from '../../rules/fieldTypes.ts'
import SegmentedControl from './SegmentedControl.vue'

const props = defineProps<{
	leaf: LeafCondition
	definition: DraftDefinition
	sources: SourceField[]
	/** Fields this condition must not read (they would create a loop). */
	forbidden: ReadonlySet<string>
	removable: boolean
}>()

const emit = defineEmits<{
	update: [leaf: LeafCondition]
	remove: []
}>()

const field = computed(() => props.definition.fields[props.leaf.field])
const type = computed(() => field.value?.type ?? '')
const kind = computed(() => valueKind(props.leaf.op))
const isChoice = computed(() => ['select', 'radio', 'checkboxes'].includes(type.value))

const fieldOptions = computed(() => props.sources.map((source) => ({
	id: source.id,
	label: source.field.label || t('webforms', 'Untitled field'),
	disabled: props.forbidden.has(source.id),
})))

const opOptions = computed(() => operatorsFor(type.value).map((op) => ({ id: op, label: operatorLabel(op, type.value) })))
const choiceOptions = computed(() => (field.value?.options ?? []).map((option) => ({ id: option.value, label: option.label })))

const listText = computed(() => (Array.isArray(props.leaf.value) ? props.leaf.value.join(', ') : ''))
const scalarText = computed(() => (props.leaf.value === undefined || props.leaf.value === null ? '' : String(props.leaf.value)))
const isTemporal = computed(() => type.value === 'date' || type.value === 'time')

function setScalar(raw: string | number): void {
	const text = String(raw)
	if (type.value === 'number') {
		const parsed = Number(text)
		emit('update', { ...props.leaf, value: text.trim() === '' || Number.isNaN(parsed) ? '' : parsed })
		return
	}
	emit('update', { ...props.leaf, value: text })
}

function setList(raw: string | number): void {
	const parts = String(raw).split(',').map((part) => part.trim()).filter((part) => part !== '')
	const value = type.value === 'number' ? parts.map(Number).filter((n) => !Number.isNaN(n)) : parts
	emit('update', { ...props.leaf, value })
}
</script>

<template>
	<div class="af-cond">
		<div class="af-cond__main">
			<NcSelect :model-value="leaf.field"
				:input-label="t('webforms', 'When this field')"
				:options="fieldOptions"
				:reduce="(option: { id: string }) => option.id"
				:selectable="(option: { disabled: boolean }) => !option.disabled"
				:clearable="false"
				label="label"
				@update:model-value="(id: string) => emit('update', changeLeafField(definition, id))" />

			<NcSelect :model-value="leaf.op"
				:input-label="t('webforms', 'Comparison')"
				:options="opOptions"
				:reduce="(option: { id: string }) => option.id"
				:clearable="false"
				label="label"
				@update:model-value="(op: string) => emit('update', changeLeafOp(definition, leaf, op))" />

			<template v-if="kind !== 'none'">
				<SegmentedControl v-if="type === 'checkbox'"
					:model-value="leaf.value === false ? 'no' : 'yes'"
					:group-label="t('webforms', 'Value')"
					:options="[
						{ value: 'yes', label: t('webforms', 'Ticked') },
						{ value: 'no', label: t('webforms', 'Not ticked') },
					]"
					@update:model-value="(value) => emit('update', { ...leaf, value: value === 'yes' })" />

				<NcSelect v-else-if="isChoice && kind === 'list'"
					:model-value="Array.isArray(leaf.value) ? leaf.value : []"
					:input-label="t('webforms', 'Value')"
					:options="choiceOptions"
					:reduce="(option: { id: string }) => option.id"
					label="label"
					multiple
					@update:model-value="(value: string[]) => emit('update', { ...leaf, value })" />

				<NcSelect v-else-if="isChoice"
					:model-value="typeof leaf.value === 'string' ? leaf.value : null"
					:input-label="t('webforms', 'Value')"
					:options="choiceOptions"
					:reduce="(option: { id: string }) => option.id"
					:clearable="false"
					label="label"
					@update:model-value="(value: string) => emit('update', { ...leaf, value })" />

				<NcTextField v-else-if="kind === 'list'"
					:model-value="listText"
					:label="t('webforms', 'Values, separated by commas')"
					@update:model-value="setList" />

				<!-- NcTextField has no date or time type, so these use a native input styled to match. -->
				<label v-else-if="isTemporal" class="af-cond__native">
					<span>{{ t('webforms', 'Value') }}</span>
					<input :type="type"
						:value="scalarText"
						@input="setScalar(($event.target as HTMLInputElement).value)">
				</label>

				<NcTextField v-else
					:model-value="scalarText"
					:type="type === 'number' ? 'number' : 'text'"
					:label="t('webforms', 'Value')"
					@update:model-value="setScalar" />
			</template>
		</div>

		<!-- A rule needs one condition, so the only one has nothing to remove. -->
		<NcButton v-if="removable"
			variant="tertiary"
			class="af-cond__remove"
			:aria-label="t('webforms', 'Remove condition')"
			:title="t('webforms', 'Remove condition')"
			@click="emit('remove')">
			<template #icon>
				<Icon :path="icons.close" />
			</template>
		</NcButton>
	</div>
</template>

<style scoped>
.af-cond {
	display: flex;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}

.af-cond__main {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	min-width: 0;
}

.af-cond__main :deep(.v-select) {
	width: 100%;
}

.af-cond__native {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.af-cond__native input {
	box-sizing: border-box;
	width: 100%;
	height: var(--default-clickable-area);
	padding: 0 calc(var(--default-grid-baseline) * 3);
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
}

.af-cond__native input:hover {
	border-color: var(--color-main-text);
}

.af-cond__native input:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.af-cond__remove {
	flex: none;
}
</style>
