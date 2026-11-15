<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition, TablesAction } from '../../types.ts'
import type { SourceOption, TablesColumn, TablesList } from '../../builder/tablesMap.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { computed, onMounted, ref, watch } from 'vue'
import { fetchTableColumns, fetchTables } from '../../api/tables.ts'
import { brokenMappings, setMapping, sourcesForColumn, suggestMap } from '../../builder/tablesMap.ts'

const props = defineProps<{
	action: TablesAction
	definition: DraftDefinition
}>()

const emit = defineEmits<{
	/** Changes to merge into the action. */
	patch: [changes: Partial<TablesAction>]
}>()

const tables = ref<TablesList | null>(null)
const columns = ref<TablesColumn[]>([])
const loading = ref(true)
const loadingColumns = ref(false)
const failed = ref(false)
const columnsFailed = ref(false)

interface TableOption {
	id: number
	label: string
}

const tableOptions = computed<TableOption[]>(() => (tables.value?.tables ?? []).map((table) => ({
	id: table.id,
	label: (table.emoji ? table.emoji + ' ' : '') + table.title,
})))
const selectedTable = computed(() => tableOptions.value.find((option) => option.id === props.action.tableId) ?? null)
/** A table is set but the owner may not add rows to it (or it is gone). */
const unusable = computed(() => tables.value !== null && props.action.tableId > 0 && selectedTable.value === null)

const supported = computed(() => columns.value.filter((column) => column.supported))
const unsupported = computed(() => columns.value.filter((column) => !column.supported))
const broken = computed(() => brokenMappings(props.definition, columns.value, props.action.map))
const mappedCount = computed(() => Object.keys(props.action.map).length)

onMounted(async () => {
	try {
		tables.value = await fetchTables()
	} catch {
		failed.value = true
	} finally {
		loading.value = false
	}
	await loadColumns()
})

watch(() => props.action.tableId, () => {
	void loadColumns()
})

async function loadColumns(): Promise<void> {
	columns.value = []
	columnsFailed.value = false
	if (props.action.tableId < 1 || tables.value?.available === false || unusable.value) {
		return
	}
	loadingColumns.value = true
	try {
		columns.value = await fetchTableColumns(props.action.tableId)
	} catch {
		columnsFailed.value = true
	} finally {
		loadingColumns.value = false
	}
}

function pickTable(option: TableOption | null): void {
	// Columns belong to a table, so a new table starts with an empty mapping.
	emit('patch', { tableId: option?.id ?? 0, map: {} })
}

function optionsFor(column: TablesColumn): SourceOption[] {
	return sourcesForColumn(props.definition, column)
}

function selectedSource(column: TablesColumn): SourceOption | null {
	const source = props.action.map[String(column.id)]
	return source === undefined ? null : optionsFor(column).find((option) => option.id === source) ?? { id: source, label: source, hint: '' }
}

function pickSource(column: TablesColumn, option: SourceOption | null): void {
	emit('patch', { map: setMapping(props.action.map, column.id, option?.id ?? null) })
}

function matchByLabel(): void {
	emit('patch', { map: suggestMap(props.definition, columns.value, props.action.map) })
}

function kindLabel(column: TablesColumn): string {
	switch (column.kind) {
		case 'text': return t('webforms', 'Text')
		case 'number': return t('webforms', 'Number')
		case 'stars': return t('webforms', 'Stars')
		case 'progress': return t('webforms', 'Progress')
		case 'single': return t('webforms', 'Choice')
		case 'multi': return t('webforms', 'Several choices')
		case 'check': return t('webforms', 'Yes or no')
		case 'date': return t('webforms', 'Date')
		case 'time': return t('webforms', 'Time')
		case 'datetime': return t('webforms', 'Date and time')
		default: return t('webforms', 'Cannot be filled by a form')
	}
}
</script>

<template>
	<div class="af-tables">
		<NcNoteCard v-if="failed" type="error">
			{{ t('webforms', 'Could not load your Tables. Try again later.') }}
		</NcNoteCard>
		<NcNoteCard v-else-if="!loading && tables?.available === false" type="warning">
			{{ t('webforms', 'The Tables app is not installed or not enabled on this server, so this action cannot run. Ask an administrator to enable it.') }}
		</NcNoteCard>

		<template v-else>
			<NcSelect :model-value="selectedTable"
				:input-label="t('webforms', 'Table')"
				:placeholder="loading ? t('webforms', 'Loading…') : t('webforms', 'Pick a table')"
				:options="tableOptions"
				:loading="loading"
				label="label"
				:clearable="false"
				@update:model-value="pickTable" />
			<p v-if="!loading && tableOptions.length === 0" class="af-tables__hint">
				{{ t('webforms', 'You have no Tables tables you can add rows to. Create one in the Tables app first.') }}
			</p>
			<NcNoteCard v-if="unusable" type="warning">
				{{ t('webforms', 'You cannot add rows to the table this action points at (it may have been deleted or unshared). Pick another table.') }}
			</NcNoteCard>
			<p class="af-tables__hint">
				{{ t('webforms', 'Rows are added as you, so you need to be allowed to add rows to the table. Each response becomes one row.') }}
			</p>

			<template v-if="action.tableId > 0 && !unusable">
				<NcNoteCard v-if="columnsFailed" type="error">
					{{ t('webforms', 'Could not load the table columns.') }}
				</NcNoteCard>
				<p v-else-if="loadingColumns" class="af-tables__hint">
					{{ t('webforms', 'Loading columns…') }}
				</p>

				<template v-else-if="columns.length > 0">
					<NcNoteCard v-if="broken.length > 0" type="warning">
						{{ t('webforms', 'Some mapped columns no longer exist or no longer fit their answer. Choose them again.') }}
					</NcNoteCard>

					<div class="af-tables__head">
						<span class="af-tables__caption">{{ t('webforms', 'Fill these columns') }}</span>
						<NcButton variant="tertiary" @click="matchByLabel">
							{{ t('webforms', 'Match by name') }}
						</NcButton>
					</div>

					<div v-for="column in supported" :key="column.id" class="af-tables__row">
						<div class="af-tables__column">
							<strong>{{ column.title }}<span v-if="column.mandatory" :title="t('webforms', 'Required in Tables')"> *</span></strong>
							<span class="af-tables__kind">{{ kindLabel(column) }}</span>
						</div>
						<NcSelect :model-value="selectedSource(column)"
							class="af-tables__source"
							:input-label="t('webforms', 'Gets the answer from')"
							:placeholder="t('webforms', 'Leave empty')"
							:options="optionsFor(column)"
							label="label"
							@update:model-value="(option: SourceOption | null) => pickSource(column, option)">
							<template #option="option">
								<span>{{ option.label }}</span>
								<span class="af-tables__kind"> · {{ option.hint }}</span>
							</template>
						</NcSelect>
					</div>

					<p v-if="unsupported.length > 0" class="af-tables__hint">
						{{ t('webforms', 'Not available: {names}. People and relation columns cannot be filled from a form.', { names: unsupported.map((column) => column.title).join(', ') }) }}
					</p>
					<p v-if="mappedCount === 0" class="af-tables__hint">
						{{ t('webforms', 'Choose an answer for at least one column. Answers that are empty are left out, and columns marked * are required by Tables.') }}
					</p>
				</template>
			</template>
		</template>
	</div>
</template>

<style scoped>
.af-tables {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.af-tables__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.af-tables__caption {
	font-weight: bold;
}

.af-tables__row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr);
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: end;
}

@media (max-width: 600px) {
	.af-tables__row {
		grid-template-columns: minmax(0, 1fr);
	}
}

.af-tables__column {
	display: flex;
	flex-direction: column;
	min-width: 0;
	overflow-wrap: anywhere;
}

.af-tables__kind,
.af-tables__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.af-tables__source {
	min-width: 0;
}
</style>
