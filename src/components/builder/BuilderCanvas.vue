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
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { MAX_PAGES, dropField, resizeField, rulesTouching, type DropTarget } from '../../builder/definition.ts'
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
	/** Drag and drop: a field to a new place, or a whole row to a row slot of a page. */
	dropField: [fieldId: string, target: DropTarget]
	dropRow: [rowId: string, page: number, index: number]
	/** Resize a cell; the span is in fractional twelfths and is snapped by the receiver. */
	resize: [fieldId: string, rawSpan: number]
	pageTitle: [pageId: string, title: string]
	movePage: [pageId: string, direction: -1 | 1]
	removePage: [pageId: string]
}>()

type Drag = { kind: 'field', fieldId: string } | { kind: 'row', rowId: string }
const drag = ref<Drag | null>(null)
const dropHint = ref<{ key: string, gap?: number, cellId?: string, side?: 'before' | 'after' } | null>(null)
// While a resize handle is dragged the canvas shows the result without touching the draft;
// one edit is emitted when the pointer is released.
const resizing = ref<{ fieldId: string, raw: number, left: number, colWidth: number, gap: number } | null>(null)
// Likewise a field dragged over another cell: the canvas previews the layout it would give.
const view = computed(() => {
	if (resizing.value) {
		return resizeField(props.definition, resizing.value.fieldId, resizing.value.raw)
	}
	const hint = dropHint.value
	if (drag.value?.kind === 'field' && hint?.cellId) {
		return dropField(props.definition, drag.value.fieldId, { kind: 'cell', cellId: hint.cellId, side: hint.side ?? 'after' })
	}
	return props.definition
})
const pages = computed(() => view.value.pages)

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

// Drag and drop (native HTML5, so it needs a mouse; the properties panel keeps the button moves).
let tabTimer: ReturnType<typeof setTimeout> | undefined

function startDrag(event: DragEvent, value: Drag, image?: HTMLElement | null): void {
	drag.value = value
	takeSnapshot()
	if (event.dataTransfer) {
		event.dataTransfer.effectAllowed = 'move'
		event.dataTransfer.setData('text/plain', value.kind === 'field' ? value.fieldId : value.rowId)
		if (image) {
			event.dataTransfer.setDragImage(image, 16, 16)
		}
	}
}

function endDrag(): void {
	drag.value = null
	dropHint.value = null
	clearTimeout(tabTimer)
}

// Hit testing uses the layout as it was when the drag began. The preview reflows the canvas
// under the pointer, so testing against the live DOM would make the target jump around.
interface Snapshot {
	rows: { id: string, top: number, bottom: number, cells: { id: string, field: string, left: number, right: number }[] }[]
}
let snapshot: Snapshot | null = null
const panel = ref<HTMLElement | null>(null)

function takeSnapshot(): void {
	const el = panel.value
	if (!el) {
		snapshot = null
		return
	}
	const box = el.getBoundingClientRect()
	snapshot = {
		rows: [...el.querySelectorAll<HTMLElement>('.af-row')].map((row) => {
			const r = row.getBoundingClientRect()
			return {
				id: row.dataset.rowId ?? '',
				top: r.top - box.top,
				bottom: r.bottom - box.top,
				cells: [...row.querySelectorAll<HTMLElement>(':scope > .af-cell')].map((cell) => {
					const c = cell.getBoundingClientRect()
					return { id: cell.dataset.cellId ?? '', field: cell.dataset.field ?? '', left: c.left - box.left, right: c.right - box.left }
				}),
			}
		}),
	}
}

// Switching page mid-drag (tab hover) changes what is under the pointer.
watch(activeId, async () => {
	if (drag.value) {
		dropHint.value = null
		await nextTick()
		takeSnapshot()
	}
})

function overPanel(event: DragEvent): void {
	const current = drag.value
	const el = panel.value
	if (!current || !el || !snapshot) {
		return
	}
	event.preventDefault()
	const box = el.getBoundingClientRect()
	const x = event.clientX - box.left
	const y = event.clientY - box.top
	const rows = snapshot.rows
	let at = rows.findIndex((row) => y < row.bottom)
	if (at === -1) {
		at = rows.length
	}
	const row = rows[at]
	const gap = (index: number) => { dropHint.value = { key: 'gap' + index, gap: index } }
	if (!row || y < row.top) {
		return gap(at)
	}
	const rel = (y - row.top) / (row.bottom - row.top)
	if (current.kind === 'row') {
		return gap(rel < 0.5 ? at : at + 1)
	}
	// The top and bottom edge of a row mean "a row of its own"; the middle means "beside this field".
	if (rel < 0.22) {
		return gap(at)
	}
	if (rel > 0.78) {
		return gap(at + 1)
	}
	const cell = row.cells.find((c) => x >= c.left && x <= c.right)
		?? row.cells.reduce((best, c) => (Math.min(Math.abs(x - c.left), Math.abs(x - c.right)) < Math.min(Math.abs(x - best.left), Math.abs(x - best.right)) ? c : best))
	if (cell.field === current.fieldId) {
		return
	}
	const across = (x - cell.left) / (cell.right - cell.left)
	const previous = dropHint.value?.cellId === cell.id ? dropHint.value.side : undefined
	// A dead zone in the middle keeps the last side, so the preview does not flicker.
	const side = across < 0.4 ? 'before' : across > 0.6 ? 'after' : (previous ?? (across < 0.5 ? 'before' : 'after'))
	dropHint.value = { key: cell.id, cellId: cell.id, side }
}

function dropOnPanel(): void {
	const current = drag.value
	const hint = dropHint.value
	endDrag()
	if (!current || !hint) {
		return
	}
	if (current.kind === 'field' && hint.cellId) {
		emit('dropField', current.fieldId, { kind: 'cell', cellId: hint.cellId, side: hint.side ?? 'after' })
	} else if (hint.gap !== undefined && current.kind === 'field') {
		emit('dropField', current.fieldId, { kind: 'row', page: activeIndex.value, index: hint.gap })
	} else if (hint.gap !== undefined && current.kind === 'row') {
		emit('dropRow', current.rowId, activeIndex.value, hint.gap)
	}
}

/** Leaving the page area clears the preview. Moving between cells must not, or the layout flickers. */
function leavePage(event: DragEvent): void {
	if (!(event.currentTarget as HTMLElement).contains(event.relatedTarget as Node | null)) {
		dropHint.value = null
	}
}

/** Row slots are indexes into the saved layout, which the preview may have reshuffled. */
function slot(rowId: string, index: number): number {
	const saved = props.definition.pages[activeIndex.value]?.rows ?? []
	const found = saved.findIndex((row) => row.id === rowId)
	return found === -1 ? index : found
}

/** Hovering a tab while dragging opens that page, so a field can be dropped there. */
function overTab(pageId: string): void {
	if (drag.value && pageId !== activeId.value) {
		clearTimeout(tabTimer)
		tabTimer = setTimeout(() => { activeId.value = pageId }, 450)
	}
}

onBeforeUnmount(() => clearTimeout(tabTimer))

function startResize(event: PointerEvent, fieldId: string, span: number): void {
	const handle = event.currentTarget as HTMLElement
	const cell = handle.parentElement as HTMLElement
	const row = cell.parentElement as HTMLElement
	const gap = parseFloat(getComputedStyle(row).columnGap) || 0
	handle.setPointerCapture(event.pointerId)
	resizing.value = {
		fieldId,
		raw: span,
		left: cell.getBoundingClientRect().left,
		colWidth: (row.getBoundingClientRect().width + gap) / 12,
		gap,
	}
}

function moveResize(event: PointerEvent): void {
	const state = resizing.value
	if (state) {
		state.raw = (event.clientX - state.left + state.gap) / state.colWidth
	}
}

function endResize(commit: boolean): void {
	const state = resizing.value
	resizing.value = null
	if (state && commit && resizeField(props.definition, state.fieldId, state.raw) !== props.definition) {
		emit('resize', state.fieldId, state.raw)
	}
}

function resizeKey(event: KeyboardEvent, fieldId: string, span: number): void {
	const step = event.key === 'ArrowRight' ? 2 : event.key === 'ArrowLeft' ? -2 : 0
	if (step !== 0) {
		event.preventDefault()
		emit('resize', fieldId, span + step)
	}
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

			<div v-else
					ref="panel"
					role="tabpanel"
					:class="{ 'af-dragging': drag }"
					@dragover="overPanel"
					@dragleave="leavePage"
					@drop.prevent="dropOnPanel">
					<template v-for="(row, index) in activePage.rows" :key="row.id">
						<div class="af-gap"
							:class="{ 'af-gap--over': dropHint?.key === 'gap' + slot(row.id, index) }">
							<AddFieldMenu @pick="(type) => emit('add', type, slot(row.id, index), activeIndex)" />
						</div>
						<div class="af-row"
							:data-row-id="row.id"
							:class="{ 'af-row--dragged': drag?.kind === 'row' && drag.rowId === row.id }">
							<span class="af-row__grip"
								draggable="true"
								role="img"
								:title="t('advancedforms', 'Drag to move this row')"
								:aria-label="t('advancedforms', 'Drag to move this row')"
								@dragstart="startDrag($event, { kind: 'row', rowId: row.id }, ($event.currentTarget as HTMLElement).parentElement)"
								@dragend="endDrag">
								<Icon :path="icons.drag" :size="16" />
							</span>
							<div v-for="cell in row.cells"
								:key="cell.id"
								:data-cell-id="cell.id"
								:data-field="cell.field"
								class="af-cell"
								:class="{
									'af-cell--dragged': drag?.kind === 'field' && drag.fieldId === cell.field,
									'af-cell--resizing': resizing?.fieldId === cell.field,
								}"
								:style="{ '--af-span': cell.span }"
								:draggable="resizing === null"
								@dragstart.self="startDrag($event, { kind: 'field', fieldId: cell.field })"
								@dragend="endDrag">
								<FieldCard v-if="definition.fields[cell.field]"
									:field="definition.fields[cell.field]"
									:selected="selectedId === cell.field"
									:rule-count="rulesTouching(definition, cell.field).length"
									:errors="codes(cell.field)"
									:label-position="renderedLabelPosition(definition.fields[cell.field], definition.settings)"
									@select="emit('select', cell.field)" />
								<span v-if="resizing?.fieldId === cell.field" class="af-cell__span">{{ cell.span }}/12</span>
								<button class="af-cell__resize"
									type="button"
									draggable="false"
									:title="t('advancedforms', 'Drag to resize, or use the arrow keys')"
									:aria-label="t('advancedforms', 'Resize field')"
									@mousedown.prevent
									@dragstart.prevent.stop
									@click.stop
									@pointerdown.prevent.stop="startResize($event, cell.field, cell.span)"
									@pointermove="moveResize"
									@pointerup="endResize(true)"
									@pointercancel="endResize(false)"
									@keydown="resizeKey($event, cell.field, cell.span)" />
							</div>
						</div>
					</template>
					<div class="af-gap" :class="{ 'af-gap--over': dropHint?.key === 'gap' + activePage.rows.length }" />
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
	position: relative;
	display: grid;
	grid-template-columns: repeat(12, minmax(0, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
}

.af-cell {
	position: relative;
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

<style scoped>
.af-row__grip {
	position: absolute;
	top: 50%;
	left: -16px;
	display: flex;
	align-items: center;
	height: 32px;
	margin-top: -16px;
	color: var(--color-text-maxcontrast);
	cursor: grab;
	opacity: 0;
	transition: opacity 0.12s ease;
}

.af-row:hover > .af-row__grip,
.af-dragging .af-row__grip {
	opacity: 1;
}

.af-row--dragged,
.af-cell--dragged {
	opacity: 0.4;
}

.af-gap--over {
	border-radius: var(--border-radius-small);
	background: var(--color-primary-element-light);
	box-shadow: inset 0 0 0 2px var(--color-primary-element);
}


.af-cell__resize {
	position: absolute;
	top: 0;
	right: 0;
	bottom: 0;
	z-index: 1;
	/* Inside the cell: each cell is its own stacking context (container-type), so the next cell would cover anything overhanging. */
	width: 14px;
	min-height: 0;
	padding: 0;
	border: 0;
	background: transparent;
	cursor: col-resize;
	touch-action: none;
	opacity: 0;
}

.af-cell__resize::after {
	content: '';
	position: absolute;
	top: 25%;
	bottom: 25%;
	right: 3px;
	width: 4px;
	border-radius: 2px;
	background: var(--color-primary-element);
}

.af-cell:hover > .af-cell__resize,
.af-cell__resize:focus-visible,
.af-cell--resizing > .af-cell__resize {
	opacity: 1;
}

.af-cell__span {
	position: absolute;
	top: 4px;
	right: 12px;
	z-index: 2;
	padding: 0 calc(var(--default-grid-baseline) * 1.5);
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 12px;
	line-height: 20px;
	pointer-events: none;
}

@container (max-width: 600px) {
	.af-cell__resize,
	.af-row__grip {
		display: none;
	}
}

@media (hover: none) {
	.af-cell__resize,
	.af-row__grip {
		display: none;
	}
}
</style>
