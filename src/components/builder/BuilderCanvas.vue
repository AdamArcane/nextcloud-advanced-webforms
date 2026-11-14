<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActions from '@nextcloud/vue/components/NcActions'
import { computed, nextTick, ref, watch } from 'vue'
import { MAX_PAGES, rulesTouching } from '../../builder/definition.ts'
import { renderedLabelPosition } from '../../layout/labelPosition.ts'
import { groupErrors } from '../../builder/errors.ts'
import Icon from '../common/Icon.vue'
import { icons } from '../../icons.ts'
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
	/** Insert a field in a row of its own at this row index of a page. */
	add: [type: string, rowIndex: number, pageIndex: number]
	addPage: []
	pageTitle: [pageId: string, title: string]
	movePage: [pageId: string, direction: -1 | 1]
	removePage: [pageId: string]
}>()

const pages = computed(() => props.definition.pages)

// The page being edited. Everything on the canvas happens on this one page.
const activeId = ref(pages.value[0]?.id ?? '')
const activeIndex = computed(() => Math.max(0, pages.value.findIndex((page) => page.id === activeId.value)))
const activePage = computed(() => pages.value[activeIndex.value])
const isEmpty = computed(() => activePage.value.rows.length === 0 && pages.value.length === 1)

// Selecting a field elsewhere (a rule, an error) switches to its page.
watch(() => props.selectedId, (fieldId) => {
	const page = pages.value.find((candidate) => candidate.rows.some((row) => row.cells.some((cell) => cell.field === fieldId)))
	if (page) {
		activeId.value = page.id
	}
})

// A new page is opened straight away; a deleted active page falls back to its neighbour.
watch(() => pages.value.map((page) => page.id), (ids, before) => {
	const added = ids.find((id) => !before.includes(id))
	if (added) {
		activeId.value = added
	} else if (!ids.includes(activeId.value)) {
		activeId.value = ids[Math.min(before.indexOf(activeId.value), ids.length - 1)]
	}
})

const errorsByField = computed(() => groupErrors(props.errors))

/** Pages with a problem: one of their fields has an error, or the page itself does. */
const pagesWithErrors = computed(() => {
	const flagged = new Set<string>()
	pages.value.forEach((page, index) => {
		const own = props.errors.some((error) => error.path.startsWith('pages[' + index + ']'))
		const fieldErrors = page.rows.some((row) => row.cells.some((cell) => (errorsByField.value.get(cell.field) ?? []).length > 0))
		if (own || fieldErrors) {
			flagged.add(page.id)
		}
	})
	return flagged
})

function tabName(index: number): string {
	return pages.value[index].title || t('advancedforms', 'Page {n}', { n: index + 1 })
}

const renamingId = ref<string | null>(null)

async function startRename(pageId: string) {
	renamingId.value = pageId
	await nextTick()
	document.querySelector<HTMLInputElement>('.af-tab__rename')?.select()
}

function codes(fieldId: string): string[] {
	return (errorsByField.value.get(fieldId) ?? []).map((error) => error.code)
}
</script>

<template>
	<div class="af-canvas" :class="{ 'af-canvas--mobile': mobile }">
		<div class="af-canvas__paper">
			<div class="af-tabs" role="tablist" :aria-label="t('advancedforms', 'Pages')">
				<div v-for="(page, index) in pages"
					:key="page.id"
					class="af-tab"
					:class="{ 'af-tab--active': page.id === activeId }">
					<input v-if="renamingId === page.id"
						class="af-tab__rename"
						type="text"
						maxlength="255"
						:value="page.title ?? ''"
						:aria-label="t('advancedforms', 'Page title')"
						:placeholder="t('advancedforms', 'Page {n}', { n: index + 1 })"
						@input="emit('pageTitle', page.id, ($event.target as HTMLInputElement).value)"
						@keydown.enter="renamingId = null"
						@keydown.esc="renamingId = null"
						@blur="renamingId = null">
					<button v-else
						class="af-tab__label"
						type="button"
						role="tab"
						:aria-selected="page.id === activeId"
						@click="activeId = page.id"
						@dblclick="startRename(page.id)">
						{{ tabName(index) }}
						<span v-if="pagesWithErrors.has(page.id)"
							class="af-tab__error"
							role="img"
							:aria-label="t('advancedforms', 'This page has a problem')" />
					</button>
					<NcActions v-if="page.id === activeId" :aria-label="t('advancedforms', 'Page options')" force-menu>
						<NcActionButton @click="startRename(page.id)">
							<template #icon>
								<Icon :path="icons.edit" />
							</template>
							{{ t('advancedforms', 'Rename page') }}
						</NcActionButton>
						<NcActionButton :disabled="index === 0" @click="emit('movePage', page.id, -1)">
							<template #icon>
								<Icon :path="icons.arrowLeft" />
							</template>
							{{ t('advancedforms', 'Move page left') }}
						</NcActionButton>
						<NcActionButton :disabled="index === pages.length - 1" @click="emit('movePage', page.id, 1)">
							<template #icon>
								<Icon :path="icons.arrowRight" />
							</template>
							{{ t('advancedforms', 'Move page right') }}
						</NcActionButton>
						<NcActionButton :disabled="pages.length === 1 || page.rows.length > 0"
							:title="page.rows.length > 0 ? t('advancedforms', 'Move or delete this page\'s fields first') : undefined"
							@click="emit('removePage', page.id)">
							<template #icon>
								<Icon :path="icons.delete" />
							</template>
							{{ t('advancedforms', 'Delete page') }}
						</NcActionButton>
						<NcActionCaption v-if="page.rows.length > 0" :name="t('advancedforms', 'Move or delete the fields on this page to delete it.')" />
					</NcActions>
				</div>
				<NcButton variant="tertiary"
					:disabled="pages.length >= MAX_PAGES"
					:aria-label="t('advancedforms', 'Add page')"
					:title="t('advancedforms', 'Add page')"
					@click="emit('addPage')">
					<template #icon>
						<Icon :path="icons.add" />
					</template>
				</NcButton>
			</div>

			<NcEmptyContent v-if="isEmpty"
				class="af-canvas__empty"
				:name="t('advancedforms', 'Start your form')"
				:description="t('advancedforms', 'Add your first field. You can put several fields side by side later.')">
				<template #action>
					<AddFieldMenu :label="t('advancedforms', 'Add field')" variant="primary" @pick="(type) => emit('add', type, 0, 0)" />
				</template>
			</NcEmptyContent>

			<div v-else role="tabpanel">
				<template v-for="(row, index) in activePage.rows" :key="row.id">
					<div class="af-gap">
						<AddFieldMenu @pick="(type) => emit('add', type, index, activeIndex)" />
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
								:label-position="renderedLabelPosition(definition.fields[cell.field], definition.settings)"
								@select="emit('select', cell.field)" />
						</div>
					</div>
				</template>
				<div class="af-canvas__add">
					<AddFieldMenu :label="t('advancedforms', 'Add field')"
						variant="secondary"
						@pick="(type) => emit('add', type, activePage.rows.length, activeIndex)" />
				</div>
			</div>
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
	container-type: inline-size;
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

.af-tabs {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 1);
	margin-bottom: calc(var(--default-grid-baseline) * 4);
	border-bottom: 1px solid var(--color-border);
	overflow-x: auto;
}

.af-tab {
	display: flex;
	flex: none;
	align-items: center;
	margin-bottom: -1px;
	border-bottom: 2px solid transparent;
}

.af-tab--active {
	border-bottom-color: var(--color-primary-element);
}

.af-tab__label {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 1);
	max-width: 200px;
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
	border: 0;
	background: none;
	color: var(--color-text-maxcontrast);
	font: inherit;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	cursor: pointer;
}

.af-tab--active .af-tab__label {
	color: var(--color-main-text);
	font-weight: bold;
}

.af-tab__rename {
	width: 140px;
	margin: 0 calc(var(--default-grid-baseline) * 1);
}

.af-tab__error {
	flex: none;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: var(--color-error);
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
