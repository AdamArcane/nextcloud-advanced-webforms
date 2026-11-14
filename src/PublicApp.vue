<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { PublicForm } from './types.ts'

import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { onBeforeUnmount, onMounted, ref, watchEffect } from 'vue'
import { ApiError, fetchPublicForm } from './api/public.ts'
import { pageBackground } from './design.ts'
import FormPage from './components/render/FormPage.vue'

const props = defineProps<{
	token: string
}>()

type Problem = 'not_found' | 'closed' | 'login_required' | 'failed'

const form = ref<PublicForm | null>(null)
const problem = ref<Problem | null>(null)
const closedMessage = ref<string | null>(null)

const loginUrl = generateUrl('/login?redirect_url={url}', {
	url: window.location.pathname + window.location.search,
})

onMounted(load)

// Nextcloud paints its own background on the page; a form can ask for a plain colour instead.
watchEffect(() => {
	const colour = pageBackground(form.value?.definition.settings?.design)
	if (colour) {
		document.body.style.setProperty('background', colour, 'important')
		document.body.style.setProperty('background-image', 'none', 'important')
	} else {
		document.body.style.removeProperty('background')
		document.body.style.removeProperty('background-image')
	}
})
onBeforeUnmount(() => {
	document.body.style.removeProperty('background')
	document.body.style.removeProperty('background-image')
})

async function load() {
	try {
		form.value = await fetchPublicForm(props.token)
	} catch (error) {
		if (error instanceof ApiError && error.status === 404) {
			problem.value = 'not_found'
		} else if (error instanceof ApiError && error.status === 410) {
			problem.value = 'closed'
			closedMessage.value = error.serverMessage
		} else if (error instanceof ApiError && error.status === 401) {
			problem.value = 'login_required'
		} else {
			problem.value = 'failed'
		}
	}
}

/** Fetch a fresh fill token without resetting what the person typed. */
async function refreshToken() {
	try {
		const fresh = await fetchPublicForm(props.token)
		if (form.value && fresh.versionId === form.value.versionId) {
			form.value = { ...form.value, fillToken: fresh.fillToken }
		}
	} catch {
		// The person can reload the page; their next submit will show the error.
	}
}
</script>

<template>
	<main class="af-public">
		<NcLoadingIcon v-if="!form && !problem" :size="44" />

		<NcEmptyContent v-else-if="problem === 'not_found'"
			:name="t('advancedforms', 'Form not found')"
			:description="t('advancedforms', 'This link does not lead to an open form.')" />

		<NcEmptyContent v-else-if="problem === 'closed'"
			:name="t('advancedforms', 'Form closed')"
			:description="closedMessage ?? t('advancedforms', 'This form is no longer accepting responses.')" />

		<NcEmptyContent v-else-if="problem === 'login_required'"
			:name="t('advancedforms', 'Login required')"
			:description="t('advancedforms', 'You need to log in to fill in this form.')">
			<template #action>
				<a class="button primary" :href="loginUrl">{{ t('advancedforms', 'Log in') }}</a>
			</template>
		</NcEmptyContent>

		<NcNoteCard v-else-if="problem" type="error">
			{{ t('advancedforms', 'The form could not be loaded. Please try again later.') }}
		</NcNoteCard>

		<template v-else-if="form">
			<FormPage :form="form" @refresh-token="refreshToken" />
		</template>
	</main>
</template>

<style scoped>
.af-public {
	box-sizing: border-box;
	width: 100%;
	padding: 24px 16px 48px;
	/* Centre the form column in the page. */
	display: flex;
	flex-direction: column;
	align-items: center;
}
</style>

<style>
/*
 * Nextcloud's public layout shrink-wraps its content. The form is a size container
 * (so cells can stack by their own width), and a size container has no intrinsic
 * width, so without an explicit one the page collapses to its padding.
 */
/*
 * Nextcloud's public layout fixes #content to the window and clips what overflows it, so a form
 * taller than the window could not be scrolled. Let this one scroll.
 */
#content.app-advancedforms {
	overflow-y: auto;
}

#advancedforms-public {
	box-sizing: border-box;
	flex: 1 1 100%;
	width: 100%;
	min-width: 0;
}
</style>
