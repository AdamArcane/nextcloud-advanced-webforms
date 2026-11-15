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
import { onBeforeUnmount, onMounted, ref, useTemplateRef, watchEffect } from 'vue'
import { ApiError, fetchPublicForm } from './api/public.ts'
import { pageBackground } from './design.ts'
import { reportHeight } from './embedded.ts'
import FormPage from './components/render/FormPage.vue'

const props = defineProps<{
	token: string
	/** Shown in a frame on another website: no page background, and the frame is told how tall the form is. */
	embed?: boolean
}>()

type Problem = 'not_found' | 'closed' | 'login_required' | 'failed'

const form = ref<PublicForm | null>(null)
const problem = ref<Problem | null>(null)
const closedMessage = ref<string | null>(null)
/** Why the form takes no responses: closed, not open yet, or full. */
const closedReason = ref<'closed' | 'not_open' | 'full'>('closed')

const loginUrl = generateUrl('/login?redirect_url={url}', {
	url: window.location.pathname + window.location.search,
})

/** Where the form opens on its own page, for when it cannot be used inside a frame. */
const fullPageUrl = generateUrl('/apps/webforms/f/{token}', { token: props.token })

const root = useTemplateRef<HTMLElement>('root')
let stopReporting = () => {}

onMounted(() => {
	if (props.embed && root.value) {
		stopReporting = reportHeight(root.value)
	}
	return load()
})

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
	stopReporting()
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
			closedReason.value = error.data.reason === 'not_open' || error.data.reason === 'full' ? error.data.reason : 'closed'
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
	<main ref="root" class="af-public" :class="{ 'af-public--embed': embed }">
		<NcLoadingIcon v-if="!form && !problem" :size="44" />

		<NcEmptyContent v-else-if="problem === 'not_found'"
			:name="t('webforms', 'Form not found')"
			:description="t('webforms', 'This link does not lead to an open form.')" />

		<NcEmptyContent v-else-if="problem === 'closed' && closedReason === 'not_open'"
			:name="t('webforms', 'Form not open yet')"
			:description="closedMessage ?? t('webforms', 'This form is not open yet.')" />

		<NcEmptyContent v-else-if="problem === 'closed' && closedReason === 'full'"
			:name="t('webforms', 'Form full')"
			:description="closedMessage ?? t('webforms', 'This form has received the maximum number of responses.')" />

		<NcEmptyContent v-else-if="problem === 'closed'"
			:name="t('webforms', 'Form closed')"
			:description="closedMessage ?? t('webforms', 'This form is no longer accepting responses.')" />

		<NcEmptyContent v-else-if="problem === 'login_required' && embed"
			:name="t('webforms', 'Login required')"
			:description="t('webforms', 'This form needs a login, so it cannot be filled in here.')">
			<template #action>
				<a class="button primary"
					:href="fullPageUrl"
					target="_blank"
					rel="noopener">{{ t('webforms', 'Open the form') }}</a>
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="problem === 'login_required'"
			:name="t('webforms', 'Login required')"
			:description="t('webforms', 'You need to log in to fill in this form.')">
			<template #action>
				<a class="button primary" :href="loginUrl">{{ t('webforms', 'Log in') }}</a>
			</template>
		</NcEmptyContent>

		<NcNoteCard v-else-if="problem" type="error">
			{{ t('webforms', 'The form could not be loaded. Please try again later.') }}
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

/* In a frame the website around it already gives the form its room. */
.af-public--embed {
	padding: 8px;
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
#content.app-webforms {
	overflow-y: auto;
}

/*
 * In a frame on another website. Nextcloud paints its own colour on the root element and pins the page
 * to the window, clipping what does not fit. A frame needs the opposite: let the website around it
 * show through, and let the page be as tall as the form, so a frame of a fixed height can scroll and
 * the height reported to the website is the form's own.
 */
html.af-embed,
html.af-embed body {
	background: transparent !important;
	background-image: none !important;
}

/*
 * A browser paints a solid backdrop behind a frame whose colour scheme differs from the page around
 * it, and Nextcloud declares "light dark". Websites are light unless they say otherwise, so say light.
 */
html.af-embed {
	color-scheme: light !important;
}

html.af-embed,
html.af-embed body,
html.af-embed #content {
	position: static !important;
	width: auto !important;
	height: auto !important;
	min-height: 0 !important;
	margin: 0 !important;
	padding: 0 !important;
	overflow: visible !important;
}

#webforms-public {
	box-sizing: border-box;
	flex: 1 1 100%;
	width: 100%;
	min-width: 0;
}
</style>
