<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActions from '@nextcloud/vue/components/NcActions'
import { FIELD_TYPES } from '../../builder/definition.ts'

defineProps<{
	/** Text on the trigger button. Without it the trigger is a compact plus. */
	label?: string
	variant?: 'primary' | 'secondary' | 'tertiary'
}>()

const emit = defineEmits<{
	pick: [type: string]
}>()

const inputTypes = FIELD_TYPES.filter((info) => !info.display)
const displayTypes = FIELD_TYPES.filter((info) => info.display)

function typeName(type: string): string {
	switch (type) {
		case 'text': return t('advancedforms', 'Short text')
		case 'textarea': return t('advancedforms', 'Long text')
		case 'email': return t('advancedforms', 'Email')
		case 'phone': return t('advancedforms', 'Phone')
		case 'number': return t('advancedforms', 'Number')
		case 'select': return t('advancedforms', 'Dropdown')
		case 'radio': return t('advancedforms', 'Single choice')
		case 'checkboxes': return t('advancedforms', 'Multiple choice')
		case 'checkbox': return t('advancedforms', 'Checkbox')
		case 'date': return t('advancedforms', 'Date')
		case 'time': return t('advancedforms', 'Time')
		case 'heading': return t('advancedforms', 'Heading')
		case 'paragraph': return t('advancedforms', 'Paragraph')
		default: return t('advancedforms', 'Divider')
	}
}
</script>

<template>
	<NcActions :menu-name="label"
		:variant="variant ?? 'tertiary'"
		:aria-label="label ? undefined : t('advancedforms', 'Add a field here')"
		:class="{ 'af-add-menu--compact': !label }">
		<template v-if="!label" #icon>
			<span class="af-add-menu__plus" aria-hidden="true">+</span>
		</template>
		<NcActionCaption :name="t('advancedforms', 'Inputs')" />
		<NcActionButton v-for="info in inputTypes"
			:key="info.type"
			close-after-click
			@click="emit('pick', info.type)">
			{{ typeName(info.type) }}
		</NcActionButton>
		<NcActionCaption :name="t('advancedforms', 'Content')" />
		<NcActionButton v-for="info in displayTypes"
			:key="info.type"
			close-after-click
			@click="emit('pick', info.type)">
			{{ typeName(info.type) }}
		</NcActionButton>
	</NcActions>
</template>

<style scoped>
.af-add-menu__plus {
	font-size: 20px;
	line-height: 1;
	font-weight: bold;
}
</style>
