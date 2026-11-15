<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { MailAdminSettings } from '../../api/admin.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'
import { fetchMailSettings, saveMailSettings } from '../../api/admin.ts'

/**
 * Server-wide settings for mail that forms send (docs/SCHEMA.md section 16.2): where replies go when a
 * form does not say, the name mail is sent under, and whether form owners may set their own.
 */
const loaded = ref(false)
const failed = ref(false)
const saving = ref(false)

const replyToMode = ref<MailAdminSettings['replyToMode']>('none')
const replyToAddress = ref('')
const allowOverride = ref(true)
const fromName = ref('')

// The browser's own idea of an address is close enough to catch a typo; the server decides.
const addressValid = computed(() => replyToMode.value !== 'address' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(replyToAddress.value.trim()))
const nameValid = computed(() => fromName.value.trim().length <= 100)
const canSave = computed(() => loaded.value && !saving.value && addressValid.value && nameValid.value)

function apply(settings: MailAdminSettings): void {
	replyToMode.value = settings.replyToMode
	replyToAddress.value = settings.replyToAddress
	allowOverride.value = settings.allowOverride
	fromName.value = settings.fromName
}

onMounted(async () => {
	try {
		apply(await fetchMailSettings())
		loaded.value = true
	} catch {
		failed.value = true
	}
})

async function save(): Promise<void> {
	saving.value = true
	try {
		apply(await saveMailSettings({
			replyToMode: replyToMode.value,
			replyToAddress: replyToMode.value === 'address' ? replyToAddress.value.trim() : '',
			allowOverride: allowOverride.value,
			fromName: fromName.value.trim(),
		}))
		showSuccess(t('webforms', 'Email settings saved'))
	} catch {
		showError(t('webforms', 'The email settings could not be saved. Check the reply-to address and the sender name.'))
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<NcSettingsSection :name="t('webforms', 'Emails from forms')"
		:description="t('webforms', 'Defaults for the emails that forms send. They apply at once, also to forms that are already live.')">
		<NcNoteCard v-if="failed" type="error">
			{{ t('webforms', 'The email settings could not be loaded.') }}
		</NcNoteCard>

		<div v-else-if="loaded" class="af-mail">
			<section class="af-mail__block">
				<h3>{{ t('webforms', 'Where replies go') }}</h3>
				<p class="af-mail__hint">
					{{ t('webforms', 'Used when an email action sets no reply-to address of its own.') }}
				</p>
				<NcCheckboxRadioSwitch v-model="replyToMode" type="radio" name="webforms-reply-to" value="none">
					{{ t('webforms', 'The server\'s sender address (no reply-to)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="replyToMode" type="radio" name="webforms-reply-to" value="owner">
					{{ t('webforms', 'The form owner\'s account email') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="replyToMode" type="radio" name="webforms-reply-to" value="address">
					{{ t('webforms', 'One address for every form') }}
				</NcCheckboxRadioSwitch>
				<NcTextField v-if="replyToMode === 'address'"
					v-model="replyToAddress"
					type="email"
					:label="t('webforms', 'Reply-to address')"
					:error="!addressValid"
					:helper-text="addressValid ? '' : t('webforms', 'Enter one email address.')" />
				<p class="af-mail__hint">
					{{ t('webforms', 'A default that is the same as the recipient is left out, since replies would only come back to where the email went.') }}
				</p>
			</section>

			<section class="af-mail__block">
				<h3>{{ t('webforms', 'Sender name') }}</h3>
				<NcTextField v-model="fromName"
					:label="t('webforms', 'Name emails are sent under')"
					:placeholder="t('webforms', 'Nextcloud\'s own name')"
					maxlength="100"
					:error="!nameValid"
					:helper-text="t('webforms', 'Only the name. The sender address stays the one set in Nextcloud\'s email server settings.')" />
			</section>

			<section class="af-mail__block">
				<h3>{{ t('webforms', 'Form owners') }}</h3>
				<NcCheckboxRadioSwitch v-model="allowOverride" type="switch">
					{{ t('webforms', 'Form owners may set their own reply-to address and sender name') }}
				</NcCheckboxRadioSwitch>
				<p class="af-mail__hint">
					{{ allowOverride
						? t('webforms', 'An email action\'s own reply-to and sender name win over the defaults above.')
						: t('webforms', 'The defaults above are always used. A form may still reply to the address a respondent typed in an email field. Forms that already set their own keep sending, with their own choice ignored, and cannot be published again until it is removed.') }}
				</p>
			</section>

			<NcButton variant="primary" :disabled="!canSave" @click="save">
				{{ t('webforms', 'Save') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<style scoped>
.af-mail {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 4);
	max-width: 640px;
}

.af-mail__block {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 1);
	width: 100%;
}

.af-mail__block h3 {
	margin: 0;
	font-size: 16px;
	font-weight: bold;
}

.af-mail__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
