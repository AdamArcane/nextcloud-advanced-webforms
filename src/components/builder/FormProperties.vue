<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition, FormDesign, FormSettings } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ref } from 'vue'
import { DEFAULT_ACCENT, DEFAULT_BACKGROUND_COLOR, safeColor } from '../../design.ts'
import SegmentedControl from './SegmentedControl.vue'

defineProps<{
	/** Which page of the settings to show. */
	section: 'general' | 'design' | 'sharing'
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

/** Change one design option; `undefined` puts it back to the default, and an empty design is dropped. */
function setDesign(current: FormDesign | undefined, patch: Partial<FormDesign>): void {
	const next: Record<string, unknown> = { ...current, ...patch }
	for (const key of Object.keys(next)) {
		if (next[key] === undefined) {
			delete next[key]
		}
	}
	emit('settings', { design: Object.keys(next).length > 0 ? next as FormDesign : undefined })
}

function str(value: string | number): string | undefined {
	const text = String(value)
	return text === '' ? undefined : text
}
</script>

<template>
	<div class="af-form-props">
		<section v-if="section === 'general'" class="af-form-props__section">
			<NcTextField :model-value="definition.title"
				:label="t('advancedforms', 'Title')"
				@update:model-value="(value: string | number) => emit('title', String(value))" />
			<NcTextArea :model-value="definition.description ?? ''"
				:label="t('advancedforms', 'Description')"
				rows="3"
				@update:model-value="(value: string) => emit('description', value)" />
		</section>

		<section v-if="section === 'general'" class="af-form-props__section">
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

		<section v-if="section === 'general'" class="af-form-props__section">
			<h3 class="af-form-props__heading">
				{{ t('advancedforms', 'Submitting') }}
			</h3>
			<NcTextField :model-value="definition.settings.submitLabel ?? ''"
				:label="t('advancedforms', 'Submit button text')"
				:placeholder="t('advancedforms', 'Send')"
				@update:model-value="(value: string | number) => emit('settings', { submitLabel: str(value) })" />
		</section>

		<template v-if="section === 'design'">
			<section class="af-form-props__section">
				<h3 class="af-form-props__heading">
					{{ t('advancedforms', 'Layout') }}
				</h3>
				<SegmentedControl :model-value="definition.settings.design?.width ?? 'standard'"
					:group-label="t('advancedforms', 'Form width')"
					:options="[
						{ value: 'narrow', label: t('advancedforms', 'Narrow') },
						{ value: 'standard', label: t('advancedforms', 'Standard') },
						{ value: 'wide', label: t('advancedforms', 'Wide') },
					]"
					@update:model-value="(value) => setDesign(definition.settings.design, { width: value === 'standard' ? undefined : value as FormDesign['width'] })" />
				<NcCheckboxRadioSwitch type="switch"
					:model-value="definition.settings.design?.card !== false"
					@update:model-value="(value: boolean) => setDesign(definition.settings.design, { card: value ? undefined : false })">
					{{ t('advancedforms', 'Show the form on a card') }}
				</NcCheckboxRadioSwitch>
				<p class="af-form-props__hint">
					{{ t('advancedforms', 'Width only applies on larger screens. On phones the form fills the screen and fields sit one under another.') }}
				</p>
			</section>

			<section class="af-form-props__section">
				<h3 class="af-form-props__heading">
					{{ t('advancedforms', 'Page background') }}
				</h3>
				<SegmentedControl :model-value="definition.settings.design?.background ?? 'default'"
					:group-label="t('advancedforms', 'Page background')"
					:options="[
						{ value: 'default', label: t('advancedforms', 'Nextcloud') },
						{ value: 'plain', label: t('advancedforms', 'Plain') },
						{ value: 'color', label: t('advancedforms', 'Colour') },
					]"
					@update:model-value="(value) => setDesign(definition.settings.design, { background: value === 'default' ? undefined : value as FormDesign['background'] })" />
				<label v-if="definition.settings.design?.background === 'color'" class="af-form-props__color">
					<input type="color"
						:value="safeColor(definition.settings.design?.backgroundColor) ?? DEFAULT_BACKGROUND_COLOR"
						@input="setDesign(definition.settings.design, { backgroundColor: ($event.target as HTMLInputElement).value })">
					{{ t('advancedforms', 'Background colour') }}
				</label>
			</section>

			<section class="af-form-props__section">
				<h3 class="af-form-props__heading">
					{{ t('advancedforms', 'Accent colour') }}
				</h3>
				<label class="af-form-props__color">
					<input type="color"
						:value="safeColor(definition.settings.design?.accentColor) ?? DEFAULT_ACCENT"
						@input="setDesign(definition.settings.design, { accentColor: ($event.target as HTMLInputElement).value })">
					{{ t('advancedforms', 'Buttons and highlights') }}
				</label>
				<NcButton v-if="definition.settings.design?.accentColor"
					variant="tertiary"
					@click="setDesign(definition.settings.design, { accentColor: undefined })">
					{{ t('advancedforms', 'Use the Nextcloud colour') }}
				</NcButton>
			</section>

			<section class="af-form-props__section">
				<h3 class="af-form-props__heading">
					{{ t('advancedforms', 'Submit button colour') }}
				</h3>
				<label class="af-form-props__color">
					<input type="color"
						:value="safeColor(definition.settings.design?.buttonColor) ?? safeColor(definition.settings.design?.accentColor) ?? DEFAULT_ACCENT"
						@input="setDesign(definition.settings.design, { buttonColor: ($event.target as HTMLInputElement).value })">
					{{ t('advancedforms', 'Next, Back and submit buttons') }}
				</label>
				<NcButton v-if="definition.settings.design?.buttonColor"
					variant="tertiary"
					@click="setDesign(definition.settings.design, { buttonColor: undefined })">
					{{ t('advancedforms', 'Same as the accent colour') }}
				</NcButton>
				<p class="af-form-props__hint">
					{{ t('advancedforms', 'Use Preview to see the result.') }}
				</p>
			</section>
		</template>

		<section v-if="section === 'sharing'" class="af-form-props__section">
			<h3 class="af-form-props__heading">
				{{ t('advancedforms', 'Public link') }}
			</h3>
			<template v-if="shareUrl">
				<NcTextField :model-value="shareUrl"
					:label="t('advancedforms', 'Public link')"
					readonly
					@focus="($event.target as HTMLInputElement).select()" />
				<NcButton variant="secondary" @click="copy(shareUrl)">
					<template #icon>
						<Icon :path="icons.copy" />
					</template>
					{{ copied ? t('advancedforms', 'Copied') : t('advancedforms', 'Copy link') }}
				</NcButton>
			</template>
			<p v-else class="af-form-props__hint">
				{{ t('advancedforms', 'Publish the form to get a link you can share.') }}
			</p>
		</section>

		<section v-if="section === 'sharing'" class="af-form-props__section">
			<h3 class="af-form-props__heading">
				{{ t('advancedforms', 'Who can respond') }}
			</h3>
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
			<NcTextField :model-value="definition.settings.closedMessage ?? ''"
				:label="t('advancedforms', 'Message when closed')"
				@update:model-value="(value: string | number) => emit('settings', { closedMessage: str(value) })" />
		</section>

		<section v-if="section === 'general'" class="af-form-props__section">
			<NcButton variant="error" @click="emit('delete')">
				<template #icon>
					<Icon :path="icons.delete" />
				</template>
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

.af-form-props__color {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.af-form-props__color input {
	width: 44px;
	height: 34px;
	padding: 0;
	cursor: pointer;
}

.af-form-props__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
