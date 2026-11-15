<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { onMounted, ref } from 'vue'
import { DEFAULT_PREFERENCES, fetchUserPreferences, saveUserPreferences } from './api/settings.ts'

const loaded = ref(false)
const saving = ref(false)
const openPublishedOnResponses = ref(DEFAULT_PREFERENCES.openPublishedOnResponses)

onMounted(async () => {
	openPublishedOnResponses.value = (await fetchUserPreferences()).openPublishedOnResponses
	loaded.value = true
})

async function setOpenPublishedOnResponses(value: boolean): Promise<void> {
	const previous = openPublishedOnResponses.value
	openPublishedOnResponses.value = value
	saving.value = true
	try {
		openPublishedOnResponses.value = (await saveUserPreferences({ openPublishedOnResponses: value })).openPublishedOnResponses
	} catch {
		openPublishedOnResponses.value = previous
		showError(t('webforms', 'Could not save the setting.'))
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<NcSettingsSection :name="t('webforms', 'Advanced Webforms')"
		:description="t('webforms', 'How the app behaves for you. These settings do not affect other people or your forms.')">
		<NcCheckboxRadioSwitch type="switch"
			:model-value="openPublishedOnResponses"
			:disabled="!loaded || saving"
			@update:model-value="setOpenPublishedOnResponses">
			{{ t('webforms', 'Open published forms on their responses') }}
		</NcCheckboxRadioSwitch>
		<p class="af-personal-hint">
			{{ t('webforms', 'Applies when you pick a published form that has no unpublished changes. Drafts, closed forms and forms with unpublished changes open in the editor.') }}
		</p>
	</NcSettingsSection>
</template>

<style scoped>
.af-personal-hint {
	color: var(--color-text-maxcontrast);
	margin-inline-start: 44px;
}
</style>
