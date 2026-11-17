<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { UserPreferences } from '../api/settings.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcAppSettingsDialog from '@nextcloud/vue/components/NcAppSettingsDialog'
import NcAppSettingsSection from '@nextcloud/vue/components/NcAppSettingsSection'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { ref, watch } from 'vue'
import { formsBackupUrl } from '../api/forms.ts'
import { saveUserPreferences } from '../api/settings.ts'

// The signed-in user's own preferences (docs/SCHEMA.md section 16.1), opened from the sidebar. The
// parent owns the value, because it decides where the forms in the list open; this saves a change and
// says what the server kept.
const props = defineProps<{
	preferences: UserPreferences
	/** False until the preferences have been read, so a switch is never shown with a made-up value. */
	loaded: boolean
}>()

const emit = defineEmits<{
	saved: [preferences: UserPreferences]
}>()

const open = defineModel<boolean>('open', { required: true })

const openPublishedOnResponses = ref(props.preferences.openPublishedOnResponses)
const saving = ref(false)

watch(() => props.preferences.openPublishedOnResponses, (value) => {
	openPublishedOnResponses.value = value
})

async function setOpenPublishedOnResponses(value: boolean): Promise<void> {
	const previous = openPublishedOnResponses.value
	openPublishedOnResponses.value = value
	saving.value = true
	try {
		const kept = await saveUserPreferences({ openPublishedOnResponses: value })
		openPublishedOnResponses.value = kept.openPublishedOnResponses
		emit('saved', kept)
	} catch {
		openPublishedOnResponses.value = previous
		showError(t('webforms', 'Could not save the setting.'))
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<NcAppSettingsDialog v-model:open="open"
		:name="t('webforms', 'Advanced Webforms settings')"
		:show-navigation="false"
		no-version>
		<NcAppSettingsSection id="general" :name="t('webforms', 'General')">
			<p class="af-settings-hint">
				{{ t('webforms', 'How the app behaves for you. These settings do not affect other people or your forms.') }}
			</p>
			<NcCheckboxRadioSwitch type="switch"
				:model-value="openPublishedOnResponses"
				:disabled="!loaded || saving"
				@update:model-value="setOpenPublishedOnResponses">
				{{ t('webforms', 'Open published forms on their responses') }}
			</NcCheckboxRadioSwitch>
			<p class="af-settings-hint af-settings-hint--under">
				{{ t('webforms', 'Applies when you pick a published form that has no unpublished changes. Drafts, closed forms and forms with unpublished changes open in the editor.') }}
			</p>
		</NcAppSettingsSection>
		<NcAppSettingsSection id="backup" :name="t('webforms', 'Backup')">
			<p class="af-settings-hint">
				{{ t('webforms', 'Download every form you own as files you can import again: each form\'s draft and, if it was published, its published version. Responses, uploaded files, links and webhook secrets are not included. Responses are downloaded from each form\'s Responses page.') }}
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
</style>
