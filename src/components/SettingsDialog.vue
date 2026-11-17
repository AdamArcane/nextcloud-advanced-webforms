<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { UserPreferences } from '../api/settings.ts'
import type { LabelPosition } from '../types.ts'

import { t } from '@nextcloud/l10n'
import NcAppSettingsDialog from '@nextcloud/vue/components/NcAppSettingsDialog'
import NcAppSettingsSection from '@nextcloud/vue/components/NcAppSettingsSection'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { formsBackupUrl } from '../api/forms.ts'
import { DEFAULT_ACCENT, safeColor } from '../design.ts'
import { icons } from '../icons.ts'
import SegmentedControl from './builder/SegmentedControl.vue'

// The signed-in user's own preferences (docs/SCHEMA.md section 16.1), opened from the sidebar. It only
// shows them and says what was changed: the app saves, because it also decides where the forms in the
// list open and how the responses are laid out.
defineProps<{
	preferences: UserPreferences
	/** False until the preferences have been read, so a switch is never shown with a made-up value. */
	loaded: boolean
}>()

const emit = defineEmits<{
	change: [patch: Partial<UserPreferences>]
}>()

const open = defineModel<boolean>('open', { required: true })
</script>

<template>
	<NcAppSettingsDialog v-model:open="open"
		:name="t('webforms', 'Advanced Webforms settings')"
		show-navigation
		no-version>
		<NcAppSettingsSection id="general" :name="t('webforms', 'General')">
			<p class="af-settings-hint">
				{{ t('webforms', 'Only changes how the app works for you.') }}
			</p>
			<NcCheckboxRadioSwitch type="switch"
				:model-value="preferences.openPublishedOnResponses"
				:disabled="!loaded"
				@update:model-value="(value: boolean) => emit('change', { openPublishedOnResponses: value })">
				{{ t('webforms', 'Open published forms on their responses') }}
			</NcCheckboxRadioSwitch>
			<p class="af-settings-hint af-settings-hint--under">
				{{ t('webforms', 'Drafts, closed forms and forms with unpublished changes still open in the editor.') }}
			</p>
		</NcAppSettingsSection>

		<NcAppSettingsSection id="new-forms" :name="t('webforms', 'New form defaults')">
			<p class="af-settings-hint">
				{{ t('webforms', 'Applies to forms you create from now on.') }}
			</p>
			<h4 class="af-settings-label">
				{{ t('webforms', 'Label position') }}
			</h4>
			<SegmentedControl :model-value="preferences.newFormLabelPosition"
				:group-label="t('webforms', 'Label position for new forms')"
				:options="[
					{ value: 'above', label: t('webforms', 'Above') },
					{ value: 'inline', label: t('webforms', 'Beside') },
					{ value: 'hidden', label: t('webforms', 'Hidden') },
				]"
				@update:model-value="(value) => loaded && emit('change', { newFormLabelPosition: value as LabelPosition })" />

			<h4 class="af-settings-label">
				{{ t('webforms', 'Accent colour') }}
			</h4>
			<div class="af-settings-colour">
				<label>
					<input type="color"
						:value="safeColor(preferences.newFormAccentColor) ?? DEFAULT_ACCENT"
						:disabled="!loaded"
						:aria-label="t('webforms', 'Accent colour of new forms')"
						@change="emit('change', { newFormAccentColor: ($event.target as HTMLInputElement).value })">
					{{ preferences.newFormAccentColor ? preferences.newFormAccentColor : t('webforms', 'The Nextcloud colour') }}
				</label>
				<NcButton v-if="preferences.newFormAccentColor"
					variant="tertiary"
					@click="emit('change', { newFormAccentColor: '' })">
					{{ t('webforms', 'Use the Nextcloud colour') }}
				</NcButton>
			</div>

			<NcCheckboxRadioSwitch type="switch"
				:model-value="preferences.newFormHideTitle"
				:disabled="!loaded"
				@update:model-value="(value: boolean) => emit('change', { newFormHideTitle: value })">
				{{ t('webforms', 'Leave the title off the form page') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch type="switch"
				:model-value="preferences.newFormCaptcha"
				:disabled="!loaded"
				@update:model-value="(value: boolean) => emit('change', { newFormCaptcha: value })">
				{{ t('webforms', 'Ask people to pass the spam check') }}
			</NcCheckboxRadioSwitch>
			<p class="af-settings-hint af-settings-hint--under">
				{{ t('webforms', 'Only works if an administrator has set up a spam check.') }}
			</p>
		</NcAppSettingsSection>

		<NcAppSettingsSection id="responses" :name="t('webforms', 'Responses')">
			<p class="af-settings-hint">
				{{ t('webforms', 'How responses open, on every device you use.') }}
			</p>
			<h4 class="af-settings-label">
				{{ t('webforms', 'Show responses as') }}
			</h4>
			<SegmentedControl :model-value="preferences.responsesView"
				:group-label="t('webforms', 'How to show responses')"
				:options="[
					{ value: 'table', label: t('webforms', 'Table'), icon: icons.tableView },
					{ value: 'cards', label: t('webforms', 'Cards'), icon: icons.cardView },
				]"
				@update:model-value="(value) => loaded && emit('change', { responsesView: value === 'cards' ? 'cards' : 'table' })" />
			<NcCheckboxRadioSwitch type="switch"
				:model-value="preferences.responsesSummary"
				:disabled="!loaded"
				@update:model-value="(value: boolean) => emit('change', { responsesSummary: value })">
				{{ t('webforms', 'Show the summary above the responses') }}
			</NcCheckboxRadioSwitch>
		</NcAppSettingsSection>

		<NcAppSettingsSection id="backup" :name="t('webforms', 'Backup')">
			<p class="af-settings-hint">
				{{ t('webforms', 'Download all your forms as files you can import again, without responses or uploads.') }}
			</p>
			<NcButton variant="secondary" :href="formsBackupUrl()" :download="true">
				{{ t('webforms', 'Download backup of all my forms') }}
			</NcButton>
		</NcAppSettingsSection>
	</NcAppSettingsDialog>
</template>

<style scoped>
.af-settings-hint {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}

/* Lines up with the text of the switch above it. */
.af-settings-hint--under {
	margin: 4px 0 0;
	margin-inline-start: 44px;
}

.af-settings-label {
	margin: 16px 0 6px;
	font-size: 1em;
}

.af-settings-colour {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.af-settings-colour label {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}
</style>
