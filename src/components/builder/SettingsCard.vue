<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
/**
 * One group of settings in the settings dialog: a title and a short description, then the rows.
 * The parent (`.af-form-props`) is the size container the rows use to go two columns.
 */
defineProps<{
	title?: string
	description?: string
}>()
</script>

<template>
	<section class="af-card">
		<header v-if="title" class="af-card__head">
			<h3 class="af-card__title">
				{{ title }}
			</h3>
			<p v-if="description" class="af-card__description">
				{{ description }}
			</p>
		</header>
		<div class="af-card__body">
			<slot />
		</div>
	</section>
</template>

<style scoped>
.af-card {
	padding: calc(var(--default-grid-baseline) * 4);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.af-card__head {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.af-card__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.af-card__description {
	margin: calc(var(--default-grid-baseline)) 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.af-card__body {
	display: flex;
	flex-direction: column;
}
</style>
