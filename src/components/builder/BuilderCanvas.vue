<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { computed } from 'vue'
import { rulesTouching } from '../../builder/definition.ts'
import { groupErrors } from '../../builder/errors.ts'
import AddFieldMenu from './AddFieldMenu.vue'
import FieldCard from './FieldCard.vue'

const props = defineProps<{
	definition: DraftDefinition
	selectedId: string | null
	errors: { code: string, path: string }[]
	/** Narrow preview: the same container query as the public page stacks the cells. */
	mobile: boolean
}>()

const emit = defineEmits<{
	select: [fieldId: string]
	/** Insert a field in a row of its own at this row index. */
	add: [type: string, rowIndex: number]
}>()

const rows = computed(() => props.definition.pages[0]?.rows ?? [])
const errorsByField = computed(() => groupErrors(props.errors))

function codes(fieldId: string): string[] {
	return (errorsByField.value.get(fieldId) ?? []).map((error) => error.code)
}
</script>

<template>
	<div class="af-canvas" :class="{ 'af-canvas--mobile': mobile }">
		<div class="af-canvas__paper">
			<NcEmptyContent v-if="rows.length === 0"
				class="af-canvas__empty"
				:name="t('advancedforms', 'Start your form')"
				:description="t('advancedforms', 'Add your first field. You can put several fields side by side later.')">
				<template #action>
					<AddFieldMenu :label="t('advancedforms', 'Add field')" variant="primary" @pick="(type) => emit('add', type, 0)" />
				</template>
			</NcEmptyContent>

			<template v-else>
				<template v-for="(row, index) in rows" :key="row.id">
					<div class="af-gap">
						<AddFieldMenu @pick="(type) => emit('add', type, index)" />
					</div>
					<div class="af-row">
						<div v-for="cell in row.cells"
							:key="cell.id"
							class="af-cell"
							:style="{ '--af-span': cell.span }">
							<FieldCard v-if="definition.fields[cell.field]"
								:field="definition.fields[cell.field]"
								:selected="selectedId === cell.field"
								:rule-count="rulesTouching(definition, cell.field).length"
								:errors="codes(cell.field)"
								@select="emit('select', cell.field)" />
						</div>
					</div>
				</template>
				<div class="af-canvas__add">
					<AddFieldMenu :label="t('advancedforms', 'Add field')"
						variant="secondary"
						@pick="(type) => emit('add', type, rows.length)" />
				</div>
			</template>
		</div>
	</div>
</template>

<style scoped>
.af-canvas {
	display: flex;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 6) calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 12);
}

.af-canvas__paper {
	container-type: inline-size;
	width: 100%;
	max-width: 760px;
	box-sizing: border-box;
	padding: calc(var(--default-grid-baseline) * 4);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container-large, var(--border-radius-large));
	background: var(--color-main-background);
	box-shadow: 0 1px 4px var(--color-box-shadow);
	transition: max-width 0.2s ease;
}

.af-canvas--mobile .af-canvas__paper {
	max-width: 400px;
}

.af-row {
	display: grid;
	grid-template-columns: repeat(12, minmax(0, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
}

.af-cell {
	grid-column: span var(--af-span, 12);
	min-width: 0;
}

/* Same breakpoint as the public renderer (docs/SCHEMA.md section 5). */
@container (max-width: 600px) {
	.af-cell {
		grid-column: 1 / -1;
	}
}

.af-gap {
	display: flex;
	justify-content: center;
	align-items: center;
	height: calc(var(--default-grid-baseline) * 3);
}

/* The button is taller than the gap, so it must not catch clicks meant for the cards until the gap is in use. */
.af-gap :deep(.button-vue) {
	--button-size: 24px;
	opacity: 0;
	pointer-events: none;
	transition: opacity 0.12s ease;
}

.af-gap:hover :deep(.button-vue),
.af-gap:focus-within :deep(.button-vue) {
	opacity: 1;
	pointer-events: auto;
}

/* Touch screens have no hover, so keep the insert points visible. */
@media (hover: none) {
	.af-gap :deep(.button-vue) {
		opacity: 0.6;
		pointer-events: auto;
	}
}

.af-canvas__add {
	display: flex;
	justify-content: center;
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.af-canvas__empty {
	padding: calc(var(--default-grid-baseline) * 8) 0;
}
</style>
