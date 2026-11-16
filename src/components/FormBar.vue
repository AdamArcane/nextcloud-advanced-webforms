<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { nextTick, ref, useTemplateRef } from 'vue'
import FormNav from './FormNav.vue'

// One header for every screen of a form, so switching between Edit, Settings and Responses
// changes the content below it and nothing in the bar itself moves.
const props = defineProps<{
	formId: number
	active: 'edit' | 'settings' | 'responses'
	title: string
	/** Nextcloud pins the sidebar toggle to the top right on narrow screens; keep clear of it. */
	sidebarToggle?: boolean
	/** Let the name be changed in place: double-click it, or press Enter or F2 on it. */
	renamable?: boolean
}>()

const emit = defineEmits<{
	rename: [title: string]
}>()

const editing = ref(false)
const draftName = ref('')
const nameInput = useTemplateRef<HTMLInputElement>('nameInput')

async function startRename(): Promise<void> {
	if (!props.renamable || editing.value) {
		return
	}
	draftName.value = props.title
	editing.value = true
	await nextTick()
	nameInput.value?.focus()
	nameInput.value?.select()
}

/** Keep the new name. An empty one, or the same one, changes nothing. */
function commitRename(): void {
	if (!editing.value) {
		return
	}
	editing.value = false
	const name = draftName.value.trim()
	if (name !== '' && name !== props.title) {
		emit('rename', name)
	}
}

function cancelRename(): void {
	editing.value = false
}
</script>

<template>
	<header class="af-bar" :class="{ 'af-bar--sidebar': sidebarToggle }">
		<div class="af-bar__top">
			<div class="af-bar__title">
				<input v-if="editing"
					ref="nameInput"
					v-model="draftName"
					class="af-bar__rename"
					type="text"
					maxlength="200"
					:aria-label="t('webforms', 'Form name')"
					@keydown.enter.prevent="commitRename"
					@keydown.esc.stop.prevent="cancelRename"
					@blur="commitRename">
				<h2 v-else
					class="af-bar__name"
					:class="{ 'af-bar__name--renamable': renamable }"
					:tabindex="renamable ? 0 : undefined"
					:title="renamable ? t('webforms', 'Double-click to rename') : undefined"
					@dblclick="startRename"
					@keydown.enter.prevent="startRename"
					@keydown.f2.prevent="startRename">
					{{ title }}
				</h2>
				<slot name="meta" />
			</div>
			<div class="af-bar__icons">
				<slot name="icons" />
			</div>
		</div>
		<div class="af-bar__tools">
			<FormNav :form-id="formId" :active="active" />
			<div class="af-bar__center">
				<slot name="center" />
			</div>
			<div class="af-bar__end">
				<slot name="end" />
			</div>
		</div>
	</header>
</template>

<style scoped>
.af-bar {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	/* Leave room for the navigation toggle that Nextcloud pins to the top corner. */
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 2) calc(var(--default-clickable-area) + var(--default-grid-baseline) * 4);
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.af-bar__top {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	/* The row is as tall as an icon button even when it has none, so the bar never changes height. */
	min-height: var(--default-clickable-area);
	min-width: 0;
}

.af-bar__title {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 3);
	min-width: 0;
}

.af-bar__name {
	margin: 0;
	font-size: 20px;
	font-weight: bold;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 32ch;
}

.af-bar__name--renamable {
	cursor: text;
	border-radius: var(--border-radius);
}

.af-bar__name--renamable:hover {
	background: var(--color-background-hover);
}

.af-bar__name--renamable:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.af-bar__rename {
	margin: 0;
	font-size: 20px;
	font-weight: bold;
	width: 32ch;
	max-width: 100%;
	min-width: 0;
}

.af-bar__icons {
	display: flex;
	flex: 0 0 auto;
	gap: 2px;
}

/* View switch on the left, device in the middle, the main action on the right. */
.af-bar__tools {
	display: grid;
	grid-template-columns: 1fr auto 1fr;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	min-height: var(--default-clickable-area);
}

.af-bar__tools > :first-child {
	justify-self: start;
}

.af-bar__center {
	justify-self: center;
	min-width: 84px;
}

.af-bar__end {
	display: flex;
	justify-content: flex-end;
}

@media (max-width: 1024px) {
	.af-bar--sidebar .af-bar__top {
		padding-inline-end: var(--default-clickable-area);
	}
}

/* On a phone the canvas already is phone-sized, so the device switch only takes room. */
@media (max-width: 700px) {
	.af-bar__tools {
		grid-template-columns: 1fr auto;
	}

	.af-bar__center {
		display: none;
	}
}
</style>
