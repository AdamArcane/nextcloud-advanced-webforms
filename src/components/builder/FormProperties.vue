<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition, FormDesign, FormSettings, OwnerForm } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref } from 'vue'
import { availabilityNote, fromLocalInput, toLocalInput } from '../../availability.ts'
import { DEFAULT_ACCENT, DEFAULT_BACKGROUND_COLOR, safeColor } from '../../design.ts'
import SegmentedControl from './SegmentedControl.vue'

const props = defineProps<{
	/** Which page of the settings to show. */
	section: 'general' | 'design' | 'sharing'
	definition: DraftDefinition
	/** The public link, once the form has been published. */
	shareUrl: string | null
	archived?: boolean
	/** The form as the server has it, for what the published form is doing now. */
	form?: OwnerForm | null
}>()

const emit = defineEmits<{
	title: [title: string]
	description: [description: string]
	settings: [patch: Partial<FormSettings>]
	archive: []
	delete: []
}>()

const copied = ref(false)

const published = computed(() => (props.form ? availabilityNote(props.form) : null))
const windowInvalid = computed(() => {
	const { opensAt, closesAt } = props.definition.settings
	return opensAt !== undefined && closesAt !== undefined && closesAt <= opensAt
})

/** A whole number from 1 to a million, or undefined (no limit) for anything else. */
function limit(value: string | number): number | undefined {
	const number = Number(value)
	return String(value).trim() !== '' && Number.isInteger(number) && number >= 1 && number <= 1000000 ? number : undefined
}

function setLimit(value: string | number): void {
	const maxResponses = limit(value)
	// Without a limit there is nothing left to count down.
	emit('settings', maxResponses === undefined ? { maxResponses, showRemaining: undefined } : { maxResponses })
}

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
				:label="t('webforms', 'Title')"
				@update:model-value="(value: string | number) => emit('title', String(value))" />
			<NcTextArea :model-value="definition.description ?? ''"
				:label="t('webforms', 'Description')"
				rows="3"
				@update:model-value="(value: string) => emit('description', value)" />
			<NcCheckboxRadioSwitch type="switch"
				:model-value="definition.settings.hideTitle !== true"
				@update:model-value="(value: boolean) => emit('settings', { hideTitle: value ? undefined : true })">
				{{ t('webforms', 'Show the title on the form page') }}
			</NcCheckboxRadioSwitch>
		</section>

		<section v-if="section === 'general'" class="af-form-props__section">
			<h3 class="af-form-props__heading">
				{{ t('webforms', 'Labels') }}
			</h3>
			<SegmentedControl :model-value="definition.settings.labelPosition ?? 'above'"
				:group-label="t('webforms', 'Label position for the whole form')"
				:options="[
					{ value: 'above', label: t('webforms', 'Above') },
					{ value: 'inline', label: t('webforms', 'Beside') },
					{ value: 'hidden', label: t('webforms', 'Hidden') },
				]"
				@update:model-value="(value) => emit('settings', { labelPosition: value === 'above' ? undefined : value as 'inline' | 'hidden' })" />
			<p class="af-form-props__hint">
				{{ t('webforms', 'Each field can override this. Beside labels stay above long text and choice fields.') }}
			</p>
			<NcCheckboxRadioSwitch type="switch"
				:model-value="definition.settings.hideRequiredIndicator === true"
				@update:model-value="(value: boolean) => emit('settings', { hideRequiredIndicator: value ? true : undefined })">
				{{ t('webforms', 'Hide the required indicator (*)') }}
			</NcCheckboxRadioSwitch>
		</section>

		<section v-if="section === 'general'" class="af-form-props__section">
			<h3 class="af-form-props__heading">
				{{ t('webforms', 'Submitting') }}
			</h3>
			<NcTextField :model-value="definition.settings.submitLabel ?? ''"
				:label="t('webforms', 'Submit button text')"
				:placeholder="t('webforms', 'Send')"
				@update:model-value="(value: string | number) => emit('settings', { submitLabel: str(value) })" />
		</section>

		<template v-if="section === 'design'">
			<section class="af-form-props__section">
				<h3 class="af-form-props__heading">
					{{ t('webforms', 'Layout') }}
				</h3>
				<SegmentedControl :model-value="definition.settings.design?.width ?? 'standard'"
					:group-label="t('webforms', 'Form width')"
					:options="[
						{ value: 'narrow', label: t('webforms', 'Narrow') },
						{ value: 'standard', label: t('webforms', 'Standard') },
						{ value: 'wide', label: t('webforms', 'Wide') },
					]"
					@update:model-value="(value) => setDesign(definition.settings.design, { width: value === 'standard' ? undefined : value as FormDesign['width'] })" />
				<NcCheckboxRadioSwitch type="switch"
					:model-value="definition.settings.design?.card !== false"
					@update:model-value="(value: boolean) => setDesign(definition.settings.design, { card: value ? undefined : false })">
					{{ t('webforms', 'Show the form on a card') }}
				</NcCheckboxRadioSwitch>
				<p class="af-form-props__hint">
					{{ t('webforms', 'Width only applies on larger screens. On phones the form fills the screen and fields sit one under another.') }}
				</p>
			</section>

			<section class="af-form-props__section">
				<h3 class="af-form-props__heading">
					{{ t('webforms', 'Page background') }}
				</h3>
				<SegmentedControl :model-value="definition.settings.design?.background ?? 'default'"
					:group-label="t('webforms', 'Page background')"
					:options="[
						{ value: 'default', label: t('webforms', 'Nextcloud') },
						{ value: 'plain', label: t('webforms', 'Plain') },
						{ value: 'color', label: t('webforms', 'Colour') },
					]"
					@update:model-value="(value) => setDesign(definition.settings.design, { background: value === 'default' ? undefined : value as FormDesign['background'] })" />
				<label v-if="definition.settings.design?.background === 'color'" class="af-form-props__color">
					<input type="color"
						:value="safeColor(definition.settings.design?.backgroundColor) ?? DEFAULT_BACKGROUND_COLOR"
						@input="setDesign(definition.settings.design, { backgroundColor: ($event.target as HTMLInputElement).value })">
					{{ t('webforms', 'Background colour') }}
				</label>
			</section>

			<section class="af-form-props__section">
				<h3 class="af-form-props__heading">
					{{ t('webforms', 'Accent colour') }}
				</h3>
				<label class="af-form-props__color">
					<input type="color"
						:value="safeColor(definition.settings.design?.accentColor) ?? DEFAULT_ACCENT"
						@input="setDesign(definition.settings.design, { accentColor: ($event.target as HTMLInputElement).value })">
					{{ t('webforms', 'Buttons and highlights') }}
				</label>
				<NcButton v-if="definition.settings.design?.accentColor"
					variant="tertiary"
					@click="setDesign(definition.settings.design, { accentColor: undefined })">
					{{ t('webforms', 'Use the Nextcloud colour') }}
				</NcButton>
			</section>

			<section class="af-form-props__section">
				<h3 class="af-form-props__heading">
					{{ t('webforms', 'Submit button colour') }}
				</h3>
				<label class="af-form-props__color">
					<input type="color"
						:value="safeColor(definition.settings.design?.buttonColor) ?? safeColor(definition.settings.design?.accentColor) ?? DEFAULT_ACCENT"
						@input="setDesign(definition.settings.design, { buttonColor: ($event.target as HTMLInputElement).value })">
					{{ t('webforms', 'Only the submit button') }}
				</label>
				<NcButton v-if="definition.settings.design?.buttonColor"
					variant="tertiary"
					@click="setDesign(definition.settings.design, { buttonColor: undefined })">
					{{ t('webforms', 'Same as the accent colour') }}
				</NcButton>
				<p class="af-form-props__hint">
					{{ t('webforms', 'Use Preview to see the result.') }}
				</p>
			</section>
		</template>

		<section v-if="section === 'sharing'" class="af-form-props__section">
			<h3 class="af-form-props__heading">
				{{ t('webforms', 'Public link') }}
			</h3>
			<template v-if="shareUrl">
				<NcTextField :model-value="shareUrl"
					:label="t('webforms', 'Public link')"
					readonly
					@focus="($event.target as HTMLInputElement).select()" />
				<NcButton variant="secondary" @click="copy(shareUrl)">
					<template #icon>
						<Icon :path="icons.copy" />
					</template>
					{{ copied ? t('webforms', 'Copied') : t('webforms', 'Copy link') }}
				</NcButton>
			</template>
			<p v-else class="af-form-props__hint">
				{{ t('webforms', 'Publish the form to get a link you can share.') }}
			</p>
		</section>

		<section v-if="section === 'sharing'" class="af-form-props__section">
			<h3 class="af-form-props__heading">
				{{ t('webforms', 'Who can respond') }}
			</h3>
			<NcCheckboxRadioSwitch type="switch"
				:model-value="definition.settings.requireLogin === true"
				@update:model-value="(value: boolean) => emit('settings', { requireLogin: value ? true : undefined })">
				{{ t('webforms', 'Only logged-in users can respond') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch type="switch"
				:model-value="definition.settings.allowMultipleSubmissions !== false"
				@update:model-value="(value: boolean) => emit('settings', { allowMultipleSubmissions: value ? undefined : false })">
				{{ t('webforms', 'Allow more than one response per person') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch type="switch"
				:model-value="definition.settings.captcha !== false"
				@update:model-value="(value: boolean) => emit('settings', { captcha: value ? undefined : false })">
				{{ t('webforms', 'Spam check for people who are not logged in') }}
			</NcCheckboxRadioSwitch>
			<p class="af-form-props__hint">
				{{ t('webforms', 'The administrator chooses the kind of check. Logged-in users never see it.') }}
			</p>
			<NcTextField :model-value="definition.settings.closedMessage ?? ''"
				:label="t('webforms', 'Message when closed')"
				@update:model-value="(value: string | number) => emit('settings', { closedMessage: str(value) })" />
		</section>

		<section v-if="section === 'sharing'" class="af-form-props__section">
			<h3 class="af-form-props__heading">
				{{ t('webforms', 'Response limit') }}
			</h3>
			<NcNoteCard v-if="published" type="info" class="af-form-props__now">
				{{ published }}
			</NcNoteCard>
			<NcTextField :model-value="definition.settings.maxResponses === undefined ? '' : String(definition.settings.maxResponses)"
				:label="t('webforms', 'Maximum number of responses')"
				:placeholder="t('webforms', 'No limit')"
				type="number"
				min="1"
				max="1000000"
				step="1"
				@update:model-value="setLimit" />
			<NcCheckboxRadioSwitch type="switch"
				:model-value="definition.settings.showRemaining === true"
				:disabled="definition.settings.maxResponses === undefined"
				@update:model-value="(value: boolean) => emit('settings', { showRemaining: value ? true : undefined })">
				{{ t('webforms', 'Show respondents how many places are left') }}
			</NcCheckboxRadioSwitch>
			<NcTextField :model-value="definition.settings.fullMessage ?? ''"
				:label="t('webforms', 'Message when full')"
				:placeholder="t('webforms', 'This form has received the maximum number of responses.')"
				@update:model-value="(value: string | number) => emit('settings', { fullMessage: str(value) })" />
		</section>

		<section v-if="section === 'sharing'" class="af-form-props__section">
			<h3 class="af-form-props__heading">
				{{ t('webforms', 'Opening and closing') }}
			</h3>
			<label class="af-form-props__date">
				<span>{{ t('webforms', 'Opens') }}</span>
				<input type="datetime-local"
					:value="toLocalInput(definition.settings.opensAt)"
					@change="emit('settings', { opensAt: fromLocalInput(($event.target as HTMLInputElement).value) })">
			</label>
			<label class="af-form-props__date">
				<span>{{ t('webforms', 'Closes') }}</span>
				<input type="datetime-local"
					:value="toLocalInput(definition.settings.closesAt)"
					:aria-invalid="windowInvalid ? 'true' : undefined"
					@change="emit('settings', { closesAt: fromLocalInput(($event.target as HTMLInputElement).value) })">
			</label>
			<p v-if="windowInvalid" class="af-form-props__hint af-form-props__hint--error" role="alert">
				{{ t('webforms', 'The closing time must be after the opening time.') }}
			</p>
			<p class="af-form-props__hint">
				{{ t('webforms', 'Leave a time empty to have no opening or no closing time. Times are in your own time zone. Like the other settings here, the limit and the times apply once the form is published.') }}
			</p>
			<NcTextField :model-value="definition.settings.notOpenMessage ?? ''"
				:label="t('webforms', 'Message before opening')"
				:placeholder="t('webforms', 'This form is not open yet.')"
				@update:model-value="(value: string | number) => emit('settings', { notOpenMessage: str(value) })" />
			<p class="af-form-props__hint">
				{{ t('webforms', 'After the closing time the form shows the message when closed.') }}
			</p>
		</section>

		<section v-if="section === 'general'" class="af-form-props__section">
			<h3 class="af-form-props__heading">
				{{ t('webforms', 'Archive') }}
			</h3>
			<p class="af-form-props__hint">
				{{ archived
					? t('webforms', 'This form is archived. Its link says the form is closed and it takes no responses. Restoring it brings it back as it was.')
					: t('webforms', 'Put the form away without deleting it. It stops taking responses and moves to the Archived list, and you keep its responses.') }}
			</p>
			<NcButton variant="secondary" @click="emit('archive')">
				<template #icon>
					<Icon :path="archived ? icons.restore : icons.archive" />
				</template>
				{{ archived ? t('webforms', 'Restore form') : t('webforms', 'Archive form') }}
			</NcButton>
		</section>

		<section v-if="section === 'general'" class="af-form-props__section">
			<NcButton variant="error" @click="emit('delete')">
				<template #icon>
					<Icon :path="icons.delete" />
				</template>
				{{ t('webforms', 'Delete form') }}
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

.af-form-props__hint--error {
	color: var(--color-text-error, var(--color-error));
}

.af-form-props__now {
	margin: 0;
}

.af-form-props__date {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.af-form-props__date input {
	width: 100%;
	max-width: 280px;
	margin: 0;
}
</style>
