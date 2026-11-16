<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { StatusAdminSettings } from '../../api/admin.ts'
import type { ResponseStatus } from '../../types.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { computed, onMounted, ref } from 'vue'
import { fetchStatuses, saveStatuses } from '../../api/admin.ts'
import { ApiError } from '../../api/http.ts'
import { statusColor } from '../../entries/statusColors.ts'
import { statusIdFromLabel, statusLabel } from '../../entries/statuses.ts'
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'

// The one list of statuses a response can be given, for the whole server (docs/SCHEMA.md section 20.2).

interface Row {
	/** Stable for the list on screen. Not sent. */
	key: number
	/** Null for a status that is new: its id is made from its name when it is saved, and never changes after. */
	id: string | null
	label: string
	color: string
}

const loaded = ref(false)
const failed = ref(false)
const saving = ref(false)
const rows = ref<Row[]>([])
const colors = ref<string[]>([])
const max = ref(20)
const maxLabel = ref(40)
/** Set when the server wants an explicit yes before taking a deleted status off responses. */
const confirmAffected = ref<number | null>(null)
let nextKey = 1

const COLOR_NAMES: Record<string, () => string> = {
	gray: () => t('webforms', 'Gray'),
	red: () => t('webforms', 'Red'),
	orange: () => t('webforms', 'Orange'),
	yellow: () => t('webforms', 'Yellow'),
	green: () => t('webforms', 'Green'),
	teal: () => t('webforms', 'Teal'),
	blue: () => t('webforms', 'Blue'),
	purple: () => t('webforms', 'Purple'),
}

function colorName(color: string): string {
	return COLOR_NAMES[color]?.() ?? color
}

function apply(settings: StatusAdminSettings): void {
	colors.value = settings.colors
	max.value = settings.max
	maxLabel.value = settings.maxLabel
	rows.value = settings.statuses.map((status) => ({ key: nextKey++, id: status.id, label: statusLabel(status), color: status.color }))
	confirmAffected.value = null
}

onMounted(async () => {
	try {
		apply(await fetchStatuses())
	} catch {
		failed.value = true
	}
	loaded.value = true
})

function labelValid(row: Row): boolean {
	const label = row.label.trim()
	return label !== '' && label.length <= maxLabel.value
}

const duplicate = computed(() => {
	const seen = new Set<string>()
	for (const row of rows.value) {
		const name = row.label.trim().toLowerCase()
		if (name !== '' && seen.has(name)) {
			return true
		}
		seen.add(name)
	}
	return false
})
const canSave = computed(() => rows.value.every(labelValid) && !duplicate.value && rows.value.length <= max.value)

function add(): void {
	// A colour that is not in use yet, so new statuses are easy to tell apart.
	const used = new Set(rows.value.map((row) => row.color))
	const color = colors.value.find((candidate) => !used.has(candidate)) ?? colors.value[0] ?? 'gray'
	rows.value = [...rows.value, { key: nextKey++, id: null, label: '', color }]
	confirmAffected.value = null
}

function remove(row: Row): void {
	rows.value = rows.value.filter((existing) => existing.key !== row.key)
	confirmAffected.value = null
}

function move(index: number, by: number): void {
	const target = index + by
	if (target < 0 || target >= rows.value.length) {
		return
	}
	const next = [...rows.value]
	const [row] = next.splice(index, 1)
	next.splice(target, 0, row)
	rows.value = next
}

/** The list as the server takes it. A new status gets its id here, from its name. */
function toStatuses(): ResponseStatus[] {
	const taken = rows.value.map((row) => row.id).filter((id): id is string => id !== null)
	return rows.value.map((row) => {
		let id = row.id
		if (id === null) {
			id = statusIdFromLabel(row.label, taken)
			taken.push(id)
		}
		return { id, label: row.label.trim(), color: row.color }
	})
}

async function save(confirmDelete = false): Promise<void> {
	saving.value = true
	try {
		apply(await saveStatuses(toStatuses(), confirmDelete))
		showSuccess(t('webforms', 'Statuses saved'))
	} catch (error) {
		if (error instanceof ApiError && error.code === 'confirmation_required') {
			confirmAffected.value = Number(error.data.affected ?? 0)
		} else {
			showError(t('webforms', 'Could not save the statuses.'))
		}
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<NcSettingsSection :name="t('webforms', 'Response statuses')"
		:description="t('webforms', 'Labels that form owners can put on a response, such as Done. One list for every form on this server.')">
		<NcNoteCard v-if="failed" type="error">
			{{ t('webforms', 'Could not load the settings.') }}
		</NcNoteCard>

		<div v-else-if="loaded" class="af-statuses">
			<p v-if="rows.length === 0" class="af-statuses__hint">
				{{ t('webforms', 'There are no statuses, so responses show none.') }}
			</p>
			<ul v-else class="af-statuses__list">
				<li v-for="(row, index) in rows" :key="row.key" class="af-statuses__row">
					<span class="af-statuses__dot" :style="{ background: statusColor(row.color) }" aria-hidden="true" />
					<input v-model="row.label"
						class="af-statuses__name"
						type="text"
						:maxlength="maxLabel"
						:aria-label="t('webforms', 'Name of status {n}', { n: index + 1 })"
						:aria-invalid="!labelValid(row)"
						:placeholder="t('webforms', 'Name')">
					<div class="af-statuses__swatches" role="radiogroup" :aria-label="t('webforms', 'Colour of status {n}', { n: index + 1 })">
						<button v-for="color in colors"
							:key="color"
							type="button"
							role="radio"
							class="af-statuses__swatch"
							:class="{ 'af-statuses__swatch--on': row.color === color }"
							:style="{ '--af-swatch': statusColor(color) }"
							:aria-checked="row.color === color"
							:aria-label="colorName(color)"
							:title="colorName(color)"
							@click="row.color = color" />
					</div>
					<NcButton variant="tertiary"
						:disabled="index === 0"
						:aria-label="t('webforms', 'Move status {n} up', { n: index + 1 })"
						@click="move(index, -1)">
						<template #icon>
							<Icon :path="icons.arrowUp" />
						</template>
					</NcButton>
					<NcButton variant="tertiary"
						:disabled="index === rows.length - 1"
						:aria-label="t('webforms', 'Move status {n} down', { n: index + 1 })"
						@click="move(index, 1)">
						<template #icon>
							<Icon :path="icons.arrowDown" />
						</template>
					</NcButton>
					<NcButton variant="tertiary"
						:aria-label="t('webforms', 'Delete status {n}', { n: index + 1 })"
						@click="remove(row)">
						<template #icon>
							<Icon :path="icons.delete" />
						</template>
					</NcButton>
				</li>
			</ul>

			<NcButton variant="secondary" :disabled="rows.length >= max" @click="add">
				<template #icon>
					<Icon :path="icons.add" />
				</template>
				{{ t('webforms', 'Add a status') }}
			</NcButton>
			<p v-if="rows.length >= max" class="af-statuses__hint">
				{{ t('webforms', 'A server can have up to {max} statuses.', { max }) }}
			</p>
			<p v-if="duplicate" class="af-statuses__error" role="alert">
				{{ t('webforms', 'Two statuses have the same name.') }}
			</p>
			<p class="af-statuses__hint">
				{{ t('webforms', 'Renaming a status renames it on every response that has it. Deleting one takes it off those responses.') }}
			</p>

			<NcNoteCard v-if="confirmAffected !== null" type="warning">
				<p>
					<strong>{{ n('webforms', '%n response has a status you deleted. Saving takes the status off it.', '%n responses have a status you deleted. Saving takes the status off them.', confirmAffected) }}</strong>
				</p>
				<div class="af-statuses__confirm">
					<NcButton variant="error" :disabled="saving" @click="save(true)">
						{{ t('webforms', 'Save anyway') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="saving" @click="confirmAffected = null">
						{{ t('webforms', 'Cancel') }}
					</NcButton>
				</div>
			</NcNoteCard>

			<NcButton variant="primary" :disabled="saving || !canSave" @click="save(false)">
				{{ t('webforms', 'Save') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<style scoped>
.af-statuses {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 3);
	max-width: 640px;
}

.af-statuses__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	width: 100%;
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-statuses__row {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.af-statuses__dot {
	flex: 0 0 auto;
	width: 12px;
	height: 12px;
	border-radius: 50%;
}

.af-statuses__name {
	flex: 1 1 10rem;
	min-width: 0;
	margin: 0;
}

.af-statuses__name[aria-invalid='true'] {
	border-color: var(--color-error);
}

.af-statuses__swatches {
	display: flex;
	flex: 0 0 auto;
	flex-wrap: wrap;
	gap: 6px;
}

.af-statuses__swatch {
	width: 24px;
	height: 24px;
	min-height: 0;
	padding: 0;
	border: 2px solid transparent;
	border-radius: 50%;
	background: var(--af-swatch);
	box-shadow: inset 0 0 0 2px var(--color-main-background);
	cursor: pointer;
}

.af-statuses__swatch--on {
	border-color: var(--color-main-text);
}

.af-statuses__swatch:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.af-statuses__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.af-statuses__error {
	margin: 0;
	color: var(--color-error-text);
}

.af-statuses__confirm {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
	margin-top: var(--default-grid-baseline);
}

@media (max-width: 600px) {
	.af-statuses__row {
		flex-wrap: wrap;
	}

	/* The name takes a whole line, and the colours and buttons sit under it. */
	.af-statuses__name {
		flex-basis: calc(100% - 24px);
	}
}
</style>
