<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { OwnerForm } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref, watch } from 'vue'
import { ApiError } from '../../api/http.ts'
import { embedFormUrl, publicFormUrl, setFormShare } from '../../api/forms.ts'
import { icons } from '../../icons.ts'
import { copyText } from '../../share/clipboard.ts'
import { autoHeightSnippet, iframeSnippet, parseOrigins, slugLooksValid, tidySlug } from '../../share/embed.ts'
import { qrPng, qrSvg, saveBlob } from '../../share/qr.ts'
import Icon from '../common/Icon.vue'
import SettingsCard from './SettingsCard.vue'
import SettingsRow from './SettingsRow.vue'

/**
 * How a form is shared (docs/SCHEMA.md section 18): its link, a custom address, a QR code, and the
 * code to embed it in another website. These belong to the form, not to a published version, so they
 * are saved on their own and take effect at once.
 */
const props = defineProps<{
	form: OwnerForm
	/** Whether the form asks respondents to log in, which an embedded form cannot do. */
	requireLogin?: boolean
}>()

const emit = defineEmits<{
	changed: [form: OwnerForm]
}>()

/** The address people are given: the custom one when there is one, else the token. */
const address = computed(() => props.form.slug || props.form.publicToken)
const link = computed(() => (address.value ? publicFormUrl(address.value) : null))
const baseUrl = computed(() => publicFormUrl(''))

// ---- Copying ---------------------------------------------------------------

const copied = ref<string | null>(null)

async function copy(what: string, text: string): Promise<void> {
	await copyText(text)
	copied.value = what
	setTimeout(() => {
		if (copied.value === what) {
			copied.value = null
		}
	}, 2000)
}

// ---- Saving ----------------------------------------------------------------

const saving = ref(false)
const slugError = ref<string | null>(null)
const originsError = ref<string | null>(null)
const failed = ref(false)
const passwordError = ref<string | null>(null)

const slugText = ref(props.form.slug ?? '')
const originsText = ref((props.form.embedOrigins ?? []).join('\n'))
const passwordText = ref('')
watch(() => props.form.id, () => {
	slugText.value = props.form.slug ?? ''
	originsText.value = (props.form.embedOrigins ?? []).join('\n')
	passwordText.value = ''
	slugError.value = originsError.value = passwordError.value = null
})

const slugChanged = computed(() => slugText.value.trim() !== (props.form.slug ?? ''))
const originsChanged = computed(() => parseOrigins(originsText.value).join('\n') !== (props.form.embedOrigins ?? []).join('\n'))

function slugMessage(code: string): string {
	switch (code) {
		case 'slug_taken': return t('webforms', 'Another form already uses this address.')
		case 'slug_reserved': return t('webforms', 'This word is reserved. Choose another address.')
		default: return t('webforms', 'Use 3 to 64 lowercase letters, digits and hyphens, starting and ending with a letter or digit.')
	}
}

/** Save the sharing options, with `patch` over what the form has now. */
async function save(patch: { slug?: string | null, embed?: boolean, embedOrigins?: string[], password?: string, removePassword?: boolean }): Promise<boolean> {
	saving.value = true
	failed.value = false
	try {
		const saved = await setFormShare(props.form.id, {
			slug: props.form.slug ?? null,
			embed: props.form.embed === true,
			embedOrigins: props.form.embedOrigins ?? [],
			...patch,
		})
		emit('changed', saved)
		return true
	} catch (error) {
		const errors = error instanceof ApiError && error.status === 422 ? error.errors as unknown as Array<{ code: string, path?: string }> : []
		const slugProblem = errors.find((one) => one.path === 'slug')
		const originsProblem = errors.find((one) => one.path === 'embedOrigins')
		const passwordProblem = errors.find((one) => one.path === 'password')
		if (slugProblem) {
			slugError.value = slugMessage(slugProblem.code)
		}
		if (originsProblem) {
			originsError.value = t('webforms', 'Write each website as its address without a path, like https://www.example.com')
		}
		if (passwordProblem) {
			passwordError.value = passwordProblem.code === 'password_too_long'
				? t('webforms', 'Use at most {max} characters.', { max: MAX_PASSWORD })
				: t('webforms', 'Use at least {min} characters.', { min: MIN_PASSWORD })
		}
		failed.value = !slugProblem && !originsProblem && !passwordProblem
		return false
	} finally {
		saving.value = false
	}
}

async function saveSlug(): Promise<void> {
	slugError.value = null
	const slug = slugText.value.trim()
	if (slug !== '' && !slugLooksValid(slug)) {
		slugError.value = slugMessage('slug_invalid')
		return
	}
	if (await save({ slug: slug === '' ? null : slug })) {
		slugText.value = slug
	}
}

// The same limits as the server, so a short one is caught before it is sent (FormService::MIN_LINK_PASSWORD).
const MIN_PASSWORD = 6
const MAX_PASSWORD = 200

async function savePassword(): Promise<void> {
	passwordError.value = null
	const password = passwordText.value
	if (password.length < MIN_PASSWORD) {
		passwordError.value = t('webforms', 'Use at least {min} characters.', { min: MIN_PASSWORD })
		return
	}
	if (await save({ password })) {
		passwordText.value = ''
	}
}

async function removePassword(): Promise<void> {
	passwordError.value = null
	await save({ removePassword: true })
}

function tidy(): void {
	slugText.value = tidySlug(slugText.value)
}

async function saveOrigins(): Promise<void> {
	originsError.value = null
	await save({ embedOrigins: parseOrigins(originsText.value) })
}

// ---- QR code ---------------------------------------------------------------

const qr = computed(() => (link.value ? qrSvg(link.value) : null))
const qrImage = computed(() => (qr.value ? 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(qr.value) : null))
const qrName = computed(() => 'form-' + (address.value ?? 'qr').slice(0, 40))

function downloadSvg(): void {
	if (qr.value) {
		saveBlob(new Blob([qr.value], { type: 'image/svg+xml' }), qrName.value + '.svg')
	}
}

async function downloadPng(): Promise<void> {
	if (link.value) {
		saveBlob(await qrPng(link.value), qrName.value + '.png')
	}
}

// ---- Embedding -------------------------------------------------------------

const embedUrl = computed(() => (address.value ? embedFormUrl(address.value) : null))
/** Whether the code includes the short script that keeps the frame as tall as the form. Off is HTML only. */
const fitHeight = ref(false)
const snippet = computed(() => {
	if (!embedUrl.value) {
		return ''
	}
	return fitHeight.value
		? autoHeightSnippet(embedUrl.value, props.form.title, 'webform-' + props.form.id)
		: iframeSnippet(embedUrl.value, props.form.title)
})
</script>

<template>
	<SettingsCard :title="t('webforms', 'Public link')"
		:description="t('webforms', 'Share this link, a custom address or a QR code to let people open the form.')">
		<SettingsRow :label="t('webforms', 'Link')">
			<template v-if="link">
				<NcTextField :model-value="link"
					:label="t('webforms', 'Public link')"
					label-outside
					readonly
					@focus="($event.target as HTMLInputElement).select()" />
				<NcButton variant="secondary" @click="copy('link', link)">
					<template #icon>
						<Icon :path="icons.copy" />
					</template>
					{{ copied === 'link' ? t('webforms', 'Copied') : t('webforms', 'Copy link') }}
				</NcButton>
			</template>
			<p v-else class="af-share__hint">
				{{ t('webforms', 'Publish the form to get a link you can share.') }}
			</p>
		</SettingsRow>

		<SettingsRow :label="t('webforms', 'Custom address')"
			:hint="form.slug
				? t('webforms', 'Anyone who guesses this address can open the form. The original link keeps working too. If you change or remove the address, links and QR codes that use it stop working.')
				: t('webforms', 'Anyone who guesses a custom address can open the form, unlike the random link.')">
			<p class="af-share__hint">
				{{ t('webforms', 'A short address that is easy to read out or print. It comes after {base}', { base: baseUrl }) }}
			</p>
			<NcTextField v-model="slugText"
				:label="t('webforms', 'Custom address')"
				label-outside
				:placeholder="t('webforms', 'for example: contact')"
				:error="slugError !== null"
				:helper-text="slugError ?? ''"
				maxlength="64"
				@blur="tidy"
				@keydown.enter.prevent="saveSlug" />
			<NcButton variant="secondary" :disabled="saving || !slugChanged" @click="saveSlug">
				{{ slugText.trim() === '' && form.slug ? t('webforms', 'Remove address') : t('webforms', 'Save address') }}
			</NcButton>
		</SettingsRow>

		<SettingsRow :label="t('webforms', 'Password')"
			:hint="t('webforms', 'The password only keeps people out of the form. It does not encrypt the answers, and anyone you give it to can pass it on.')">
			<p class="af-share__hint">
				{{ form.hasPassword
					? t('webforms', 'Anyone who opens the link or the custom address is asked for a password first. Enter a new one to change it. Everyone who already got in is asked again.')
					: t('webforms', 'Ask for a password before the form opens. It applies to the link and to the custom address.') }}
			</p>
			<NcPasswordField v-model="passwordText"
				:label="form.hasPassword ? t('webforms', 'New password') : t('webforms', 'Password')"
				label-outside
				:error="passwordError !== null"
				:helper-text="passwordError ?? ''"
				autocomplete="new-password"
				maxlength="200"
				@keydown.enter.prevent="savePassword" />
			<div class="af-share__row">
				<NcButton variant="secondary" :disabled="saving || passwordText === ''" @click="savePassword">
					{{ form.hasPassword ? t('webforms', 'Change password') : t('webforms', 'Set password') }}
				</NcButton>
				<NcButton v-if="form.hasPassword" variant="tertiary" :disabled="saving" @click="removePassword">
					{{ t('webforms', 'Remove password') }}
				</NcButton>
			</div>
		</SettingsRow>

		<SettingsRow v-if="link && qrImage"
			:label="t('webforms', 'QR code')"
			:hint="t('webforms', 'The code holds the link above. It is made in your browser.')">
			<img class="af-share__qr"
				:src="qrImage"
				:alt="t('webforms', 'QR code for the public link')"
				width="180"
				height="180">
			<div class="af-share__row">
				<NcButton variant="secondary" @click="downloadPng">
					<template #icon>
						<Icon :path="icons.download" />
					</template>
					{{ t('webforms', 'Download PNG') }}
				</NcButton>
				<NcButton variant="secondary" @click="downloadSvg">
					<template #icon>
						<Icon :path="icons.download" />
					</template>
					{{ t('webforms', 'Download SVG') }}
				</NcButton>
			</div>
		</SettingsRow>
	</SettingsCard>

	<SettingsCard :title="t('webforms', 'Embed in a website')"
		:description="t('webforms', 'Show the form in a frame on another website.')">
		<SettingsRow>
			<NcCheckboxRadioSwitch type="switch"
				:model-value="form.embed === true"
				:disabled="saving"
				@update:model-value="(value: boolean) => save({ embed: value })">
				{{ t('webforms', 'Allow other websites to show this form in a frame') }}
			</NcCheckboxRadioSwitch>
		</SettingsRow>
		<template v-if="form.embed === true">
			<NcNoteCard v-if="requireLogin" type="warning">
				{{ t('webforms', 'This form requires a login. An embedded form is always anonymous, so visitors will be asked to open the form on its own page.') }}
			</NcNoteCard>
			<SettingsRow :label="t('webforms', 'Allowed websites')"
				:hint="(form.embedOrigins ?? []).length === 0
					? t('webforms', 'No websites listed: any website may embed this form.')
					: t('webforms', 'Only the listed websites may embed this form.')">
				<NcTextArea v-model="originsText"
					:label="t('webforms', 'Allowed websites (one per line)')"
					label-outside
					:placeholder="'https://www.example.com'"
					:error="originsError !== null"
					:helper-text="originsError ?? ''"
					rows="3" />
				<NcButton variant="secondary" :disabled="saving || !originsChanged" @click="saveOrigins">
					{{ t('webforms', 'Save websites') }}
				</NcButton>
			</SettingsRow>
			<SettingsRow :label="t('webforms', 'Embed code')">
				<template v-if="embedUrl">
					<NcCheckboxRadioSwitch v-model="fitHeight" type="switch">
						{{ t('webforms', 'Fit the height to the form (adds a short script)') }}
					</NcCheckboxRadioSwitch>
					<NcTextArea :model-value="snippet"
						:label="fitHeight ? t('webforms', 'Embed code (HTML and script)') : t('webforms', 'Embed code (HTML only, fixed height)')"
						label-outside
						class="af-share__code"
						readonly
						:rows="fitHeight ? 8 : 3"
						@focus="($event.target as HTMLTextAreaElement).select()" />
					<NcButton variant="secondary" @click="copy('code', snippet)">
						<template #icon>
							<Icon :path="icons.copy" />
						</template>
						{{ copied === 'code' ? t('webforms', 'Copied') : t('webforms', 'Copy code') }}
					</NcButton>
				</template>
				<p v-else class="af-share__hint">
					{{ t('webforms', 'Publish the form to get the code.') }}
				</p>
			</SettingsRow>
		</template>
		<NcNoteCard v-if="failed" type="error">
			{{ t('webforms', 'The sharing options could not be saved. Please try again.') }}
		</NcNoteCard>
	</SettingsCard>
</template>

<style scoped>
.af-share__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	overflow-wrap: anywhere;
}

.af-share__row {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

.af-share__qr {
	/* Always black on white, whatever the theme, so a phone can read it off the screen. */
	background: #ffffff;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	image-rendering: pixelated;
}

.af-share__code :deep(textarea) {
	font-family: var(--font-monospace, monospace);
	font-size: 12px;
}
</style>
