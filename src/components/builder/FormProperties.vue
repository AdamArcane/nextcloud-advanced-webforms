<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition, FormSettings } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ref } from 'vue'
import SegmentedControl from './SegmentedControl.vue'

defineProps<{
	definition: DraftDefinition
	/** The public link, once the form has been published. */
	shareUrl: string | null
}>()

const emit = defineEmits<{
	title: [title: string]
	description: [description: string]
	settings: [patch: Partial<FormSettings>]
	delete: []
}>()

const copied = ref(false)

async function copy(url: string): Promise<void> {
	try {
		await navigator.clipboard.writeText(url)
	} catch {
		// navigator.clipboard only exists on secure origins, and a dev server is often plain http.
		const area = document.createElement('textarea')
		area.value = url
		document.body.append(area)
		area.select()
		document.execCommand('copy')
		area.remove()
	}
	copied.value = true
	setTimeout(() => {
		copied.value = false
	}, 2000)
}

function str(value: string | number): string | undefined {
	const text = String(value)
	return text === '' ? undefined : text
}
</script>

<template>
	<div class="af-form-props">
		<section class="af-form-props__section">
			<NcTextField :model-value="definition.title"
				:label="t('advancedforms', 'Title')"
				@update:model-value="(value: string | number) => emit('title', String(value))" />
			<NcTextArea :model-value="definition.description ?? ''"
				:label="t('advancedforms', 'Description')"
				rows="3"
				@update:model-value="(value: string) => emit('description', value)" />
		</section>

		<section class="af-form-props__section">
			<h3 class="af-form-props__heading">
				{{ t('advancedforms', 'Labels') }}
			</h3>
			<SegmentedControl :model-value="definition.settings.labelPosition ?? 'above'"
				:group-label="t('advancedforms', 'Label position for the whole form')"
				:options="[
					{ value: 'above', label: t('advancedforms', 'Above') },
					{ value: 'inline', label: t('advancedforms', 'Beside') },
					{ value: 'hidden', label: t('advancedforms', 'Hidden') },
				]"
				@update:model-value="(value) => emit('settings', { labelPosition: value === 'above' ? undefined : value as 'inline' | 'hidden' })" />
			<p class="af-form-props__hint">
				{{ t('advancedforms', 'Each field can override this. Beside labels stay above long text and choice fields.') }}
			</p>
		</section>

		<section class="af-form-props__section">
			<h3 class="af-form-props__heading">
				{{ t('advancedforms', 'Submitting') }}
			</h3>
			<NcTextField :model-value="definition.settings.submitLabel ?? ''"
				:label="t('advancedforms', 'Submit button text')"
				:placeholder="t('advancedforms', 'Send')"
				@update:model-value="(value: string | number) => emit('settings', { submitLabel: str(value) })" />
			<NcTextField :model-value="definition.settings.closedMessage ?? ''"
				:label="t('advancedforms', 'Message when closed')"
				@update:model-value="(value: string | number) => emit('settings', { closedMessage: str(value) })" />
			<NcCheckboxRadioSwitch type="switch"
				:model-value="definition.settings.requireLogin === true"
				@update:model-value="(value: boolean) => emit('settings', { requireLogin: value ? true : undefined })">
				{{ t('advancedforms', 'Only logged-in users can respond') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch type="switch"
				:model-value="definition.settings.allowMultipleSubmissions !== false"
				@update:model-value="(value: boolean) => emit('settings', { allowMultipleSubmissions: value ? undefined : false })">
				{{ t('advancedforms', 'Allow more than one response per person') }}
			</NcCheckboxRadioSwitch>
		</section>

		<section class="af-form-props__section">
			<h3 class="af-form-props__heading">
				{{ t('advancedforms', 'Share') }}
			</h3>
			<template v-if="shareUrl">
				<NcTextField :model-value="shareUrl"
					:label="t('advancedforms', 'Public link')"
					readonly
					@focus="($event.target as HTMLInputElement).select()" />
				<NcButton variant="secondary" @click="copy(shareUrl)">
					{{ copied ? t('advancedforms', 'Copied') : t('advancedforms', 'Copy link') }}
				</NcButton>
			</template>
			<p v-else class="af-form-props__hint">
				{{ t('advancedforms', 'Publish the form to get a link you can share.') }}
			</p>
		</section>

		<section class="af-form-props__section">
			<NcButton variant="error" @click="emit('delete')">
				{{ t('advancedforms', 'Delete form') }}
			</NcButton>
		</section>
	</div>
</template>

<style scoped>
.af-form-props {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 6);
}

.af-form-props__section {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.af-form-props__heading {
	margin: 0;
	font-size: 14px;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.af-form-props__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
