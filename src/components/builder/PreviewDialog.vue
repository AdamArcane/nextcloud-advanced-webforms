<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition, PublicForm } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { computed } from 'vue'
import { cloneJson } from '../../builder/definition.ts'
import FormRenderer from '../render/FormRenderer.vue'

const props = defineProps<{
	definition: DraftDefinition
}>()

const emit = defineEmits<{
	close: []
}>()

// The same renderer the public page uses, so rules and layout behave exactly as respondents will see them.
const form = computed<PublicForm>(() => {
	const { actions: _actions, ...definition } = cloneJson(props.definition)
	return { token: 'preview', versionId: 0, fillToken: '', definition }
})
</script>

<template>
	<NcDialog :name="t('advancedforms', 'Preview')"
		size="large"
		@closing="emit('close')">
		<p class="af-preview__note">
			{{ t('advancedforms', 'This is how respondents will see the form. Nothing you enter here is saved.') }}
		</p>
		<FormRenderer :form="form" preview />
	</NcDialog>
</template>

<style scoped>
.af-preview__note {
	margin: 0 0 calc(var(--default-grid-baseline) * 4);
	color: var(--color-text-maxcontrast);
}
</style>
