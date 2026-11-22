<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { PublicForm } from '../../types.ts'

import { n } from '@nextcloud/l10n'
import { computed } from 'vue'
import { accentStyle, WIDTHS } from '../../design.ts'
import FormRenderer from './FormRenderer.vue'

const props = defineProps<{
	form: PublicForm
	/** The builder preview: nothing is submitted. */
	preview?: boolean
	/** Preview only: check required fields. */
	validate?: boolean
}>()

const emit = defineEmits<{
	refreshToken: []
}>()

// The title, description and the form itself, laid out the way the design settings ask for.
const design = computed(() => props.form.definition.settings?.design)
const style = computed(() => ({
	'--af-width': WIDTHS[design.value?.width ?? 'standard'] + 'px',
	...accentStyle(design.value),
}))
</script>

<template>
	<div class="af-page" :class="{ 'af-page--card': design?.card !== false }" :style="style">
		<header class="af-page__head">
			<h1 class="af-page__title" :class="{ 'af-page__title--hidden': form.definition.settings?.hideTitle === true }">
				{{ form.definition.title }}
			</h1>
			<p v-if="form.definition.description" class="af-page__intro">
				{{ form.definition.description }}
			</p>
			<p v-if="form.waitlisting" class="af-page__remaining" role="status">
				{{ form.waitlisting }}
			</p>
			<p v-if="typeof form.remaining === 'number' && form.remaining > 0" class="af-page__remaining">
				{{ n('webforms', '{count} place left', '{count} places left', form.remaining, { count: form.remaining }) }}
			</p>
		</header>
		<FormRenderer :form="form" :preview="preview" :validate="validate" @refresh-token="emit('refreshToken')" />
	</div>
</template>

<style scoped>
.af-page {
	box-sizing: border-box;
	width: 100%;
	max-width: var(--af-width, 760px);
	margin: 0 auto;
	padding: 8px 0 32px;
}

/* A card on top of the page background, like a Nextcloud Forms form. */
.af-page--card {
	padding: 32px;
	border-radius: var(--border-radius-container-large, 16px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 2px 12px var(--color-box-shadow);
}

.af-page__head {
	margin-bottom: 28px;
}

.af-page__head:has(.af-page__title--hidden):not(:has(.af-page__intro, .af-page__remaining)) {
	margin-bottom: 0;
}

.af-page__remaining {
	margin: 8px 0 0;
	font-weight: 600;
}

.af-page__title {
	margin: 0 0 8px;
	font-size: 28px;
	font-weight: 600;
	line-height: 1.25;
	overflow-wrap: anywhere;
}

/* Hidden visually, still the page's heading for screen readers. */
.af-page__title--hidden {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}

.af-page__intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
	white-space: pre-line;
	overflow-wrap: anywhere;
}

@media (max-width: 600px) {
	.af-page--card {
		padding: 20px 16px;
	}
}
</style>
