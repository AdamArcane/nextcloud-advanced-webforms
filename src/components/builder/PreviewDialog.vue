<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition, PublicForm } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { computed, ref } from 'vue'
import { cloneJson } from '../../builder/definition.ts'
import { pageBackground } from '../../design.ts'
import { icons } from '../../icons.ts'
import FormPage from '../render/FormPage.vue'
import SegmentedControl from './SegmentedControl.vue'

const props = defineProps<{
	definition: DraftDefinition
}>()

const emit = defineEmits<{
	close: []
}>()

type Device = 'desktop' | 'tablet' | 'mobile'

// Widths are the usual breakpoints; the renderer's layout reacts to its own width, so this is what a phone really shows.
const WIDTHS: Record<Device, number | null> = { desktop: null, tablet: 768, mobile: 390 }
const device = ref<Device>('desktop')
const validate = ref(false)
const frameWidth = computed(() => WIDTHS[device.value])
const stageBackground = computed(() => pageBackground(props.definition.settings.design))

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
		<div class="af-preview__top">
			<p class="af-preview__note">
				{{ t('advancedforms', 'This is how respondents will see the form. Nothing you enter here is saved.') }}
			</p>
			<SegmentedControl v-model="device"
				class="af-preview__device"
				:group-label="t('advancedforms', 'Preview device')"
				icon-only
				:options="[
					{ value: 'desktop', label: t('advancedforms', 'Desktop'), icon: icons.desktop },
					{ value: 'tablet', label: t('advancedforms', 'Tablet'), icon: icons.tablet },
					{ value: 'mobile', label: t('advancedforms', 'Mobile'), icon: icons.mobile },
				]" />
		</div>
		<NcCheckboxRadioSwitch v-model="validate" type="switch" class="af-preview__validate">
			{{ t('advancedforms', 'Check required fields, like a respondent would') }}
		</NcCheckboxRadioSwitch>
		<div class="af-preview__stage"
			:class="{ 'af-preview__stage--framed': frameWidth !== null || stageBackground !== null }"
			:style="stageBackground ? { background: stageBackground } : undefined">
			<div class="af-preview__frame" :style="frameWidth ? { maxWidth: frameWidth + 'px' } : undefined">
				<FormPage :form="form" preview :validate="validate" />
			</div>
		</div>
	</NcDialog>
</template>

<style scoped>
.af-preview__top {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
	margin-bottom: calc(var(--default-grid-baseline) * 4);
}

.af-preview__note {
	flex: 1 1 260px;
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.af-preview__validate {
	margin-bottom: calc(var(--default-grid-baseline) * 3);
}

.af-preview__device {
	flex: 0 0 auto;
	min-width: 132px;
}

.af-preview__stage {
	border-radius: var(--border-radius-large);
	transition: background-color 0.15s ease;
}

/* A narrower device sits on a tinted stage so its edges are visible. */
.af-preview__stage--framed {
	padding: calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 2);
	background: var(--color-background-dark);
}

.af-preview__frame {
	box-sizing: border-box;
	width: 100%;
	margin: 0 auto;
	transition: max-width 0.2s ease;
}

.af-preview__stage--framed .af-preview__frame {
	padding: calc(var(--default-grid-baseline) * 4);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

/* A card or a coloured page already frames the form; the device frame must not add a second box. */
.af-preview__stage--framed:not(:has(.af-preview__frame[style])) .af-preview__frame {
	padding: 0;
	border: 0;
	background: none;
}
</style>
