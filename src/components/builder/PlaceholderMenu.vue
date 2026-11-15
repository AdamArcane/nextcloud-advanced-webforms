<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import { computed } from 'vue'
import { sourceFields } from '../../builder/rules.ts'

const props = defineProps<{
	definition: DraftDefinition
}>()

const emit = defineEmits<{
	pick: [fieldId: string]
}>()

const fields = computed(() => sourceFields(props.definition))
</script>

<template>
	<NcActions v-if="fields.length > 0"
		:menu-name="t('webforms', 'Insert an answer')"
		variant="tertiary">
		<NcActionButton v-for="source in fields"
			:key="source.id"
			close-after-click
			@click="emit('pick', source.id)">
			{{ source.field.label || t('webforms', 'Untitled field') }}
		</NcActionButton>
	</NcActions>
</template>
