<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import SegmentedControl from './builder/SegmentedControl.vue'

const props = defineProps<{
	formId: number
	active: 'edit' | 'responses'
}>()

// The address is the source of truth for which screen is open, so going back and reloading both work.
function go(view: string | number): void {
	window.location.hash = '/f/' + props.formId + (view === 'responses' ? '/responses' : '')
}
</script>

<template>
	<SegmentedControl :model-value="active"
		class="af-formnav"
		:group-label="t('advancedforms', 'Form view')"
		:options="[
			{ value: 'edit', label: t('advancedforms', 'Edit') },
			{ value: 'responses', label: t('advancedforms', 'Responses') },
		]"
		@update:model-value="go" />
</template>

<style scoped>
.af-formnav {
	min-width: 200px;
}
</style>
