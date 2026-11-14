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
import { onMounted, ref } from 'vue'
import { ApiError, fetchPublicForm } from './api/public.ts'
import FormRenderer from './components/render/FormRenderer.vue'

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
			<h1>{{ form.definition.title }}</h1>
			<p v-if="form.definition.description" class="af-intro">
				{{ form.definition.description }}
			</p>
			<FormRenderer :form="form" @refresh-token="refreshToken" />
		</template>
	</main>
</template>

<style scoped>
.af-public {
	max-width: 760px;
	margin: 0 auto;
	padding: 24px 16px 48px;
}

.af-intro {
	margin-bottom: 24px;
	color: var(--color-text-maxcontrast);
	white-space: pre-line;
}
</style>
