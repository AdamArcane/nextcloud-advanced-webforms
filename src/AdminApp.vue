<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { CaptchaAdminSettings, CaptchaProviderId } from './api/admin.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'
import { fetchCaptchaSettings, saveCaptchaSettings } from './api/admin.ts'

const loaded = ref(false)
const saving = ref(false)
const provider = ref<CaptchaProviderId>('builtin')
const siteKey = ref('')
const secret = ref('')
const secretSet = ref(false)

const needsKeys = computed(() => provider.value === 'turnstile' || provider.value === 'hcaptcha')
const dashboard = computed(() => provider.value === 'turnstile'
	? 'https://dash.cloudflare.com/?to=/:account/turnstile'
	: 'https://dashboard.hcaptcha.com/')
const canSave = computed(() => !needsKeys.value || (siteKey.value.trim() !== '' && (secret.value.trim() !== '' || secretSet.value)))

const options: { id: CaptchaProviderId, name: string, description: string }[] = [
	{
		id: 'builtin',
		name: t('webforms', 'Built-in (recommended)'),
		description: t('webforms', 'A small calculation the browser does in the background. Nothing is shown, no account is needed, and no data leaves your server.'),
	},
	{
		id: 'turnstile',
		name: t('webforms', 'Cloudflare Turnstile'),
		description: t('webforms', 'Free and mostly invisible. Respondents\' browsers contact Cloudflare. Needs a site key and a secret key.'),
	},
	{
		id: 'hcaptcha',
		name: t('webforms', 'hCaptcha'),
		description: t('webforms', 'Shows a checkbox and sometimes a puzzle. Respondents\' browsers contact hCaptcha. Needs a site key and a secret key.'),
	},
	{
		id: 'none',
		name: t('webforms', 'No spam check'),
		description: t('webforms', 'Only the hidden trap field, the minimum fill time and the rate limit protect your forms.'),
	},
]

function apply(settings: CaptchaAdminSettings) {
	provider.value = settings.provider
	siteKey.value = settings.siteKey
	secretSet.value = settings.secretSet
	secret.value = ''
}

onMounted(async () => {
	try {
		apply(await fetchCaptchaSettings())
	} catch {
		showError(t('webforms', 'Could not load the settings.'))
	}
	loaded.value = true
})

async function save() {
	saving.value = true
	try {
		apply(await saveCaptchaSettings({
			provider: provider.value,
			siteKey: siteKey.value.trim(),
			secret: secret.value.trim() === '' ? null : secret.value.trim(),
		}))
		showSuccess(t('webforms', 'Settings saved'))
	} catch {
		showError(t('webforms', 'Could not save the settings. Check the keys and try again.'))
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<NcSettingsSection :name="t('webforms', 'Spam protection')"
		:description="t('webforms', 'Applies to people who are not logged in. Form owners can turn the check off for a single form. Every form also has a hidden trap field, a minimum fill time and a rate limit.')">
		<div v-if="loaded" class="af-admin">
			<NcCheckboxRadioSwitch v-for="option in options"
				:key="option.id"
				v-model="provider"
				type="radio"
				name="webforms-captcha"
				:value="option.id">
				<strong>{{ option.name }}</strong>
				<span class="af-admin__description">{{ option.description }}</span>
			</NcCheckboxRadioSwitch>

			<template v-if="needsKeys">
				<p class="af-admin__hint">
					{{ t('webforms', 'Create a site in the provider\'s dashboard to get the keys:') }}
					<a :href="dashboard" target="_blank" rel="noopener noreferrer">{{ dashboard }}</a>
				</p>
				<NcTextField v-model="siteKey" :label="t('webforms', 'Site key')" />
				<NcPasswordField v-model="secret"
					:label="secretSet ? t('webforms', 'Secret key (leave empty to keep the saved one)') : t('webforms', 'Secret key')"
					autocomplete="off" />
				<NcNoteCard v-if="!canSave" type="warning">
					{{ t('webforms', 'Until both keys are saved, forms are not protected by this provider.') }}
				</NcNoteCard>
			</template>

			<NcButton variant="primary" :disabled="saving || !canSave" @click="save">
				{{ t('webforms', 'Save') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<style scoped>
.af-admin {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
	max-width: 640px;
}

.af-admin__description {
	display: block;
	color: var(--color-text-maxcontrast);
}

.af-admin__hint {
	margin: 8px 0 0;
	color: var(--color-text-maxcontrast);
}
</style>
