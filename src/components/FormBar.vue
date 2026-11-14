<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import FormNav from './FormNav.vue'

// One header for every screen of a form, so switching between Edit, Settings and Responses
// changes the content below it and nothing in the bar itself moves.
defineProps<{
	formId: number
	active: 'edit' | 'settings' | 'responses'
	title: string
	/** Nextcloud pins the sidebar toggle to the top right on narrow screens; keep clear of it. */
	sidebarToggle?: boolean
}>()
</script>

<template>
	<header class="af-bar" :class="{ 'af-bar--sidebar': sidebarToggle }">
		<div class="af-bar__top">
			<div class="af-bar__title">
				<h2 class="af-bar__name">
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
