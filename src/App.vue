<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { OwnerForm } from './types.ts'

import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { createForm, listForms } from './api/forms.ts'
import BuilderView from './components/builder/BuilderView.vue'
import EntriesView from './components/entries/EntriesView.vue'
import { FORM_STATUS_CLOSED, FORM_STATUS_PUBLISHED } from './types.ts'

const forms = ref<OwnerForm[]>([])
const loading = ref(true)
const loadFailed = ref(false)
const creating = ref(false)
const createFailed = ref(false)
const route = ref(readHash())
const selectedId = computed(() => route.value.id)
const view = computed(() => route.value.view)
const selectedTitle = computed(() => forms.value.find((form) => form.id === selectedId.value)?.title ?? '')

// The selected form and screen live in the URL hash, so a reload, the back button and a shared link all work.
type View = 'edit' | 'settings' | 'responses'

function readHash(): { id: number | null, view: View } {
	const match = /^#\/f\/(\d+)(?:\/(responses|settings))?$/.exec(window.location.hash)
	return match ? { id: Number(match[1]), view: (match[2] as View | undefined) ?? 'edit' } : { id: null, view: 'edit' }
}

function onHashChange(): void {
	route.value = readHash()
}

function open(id: number | null): void {
	window.location.hash = id === null ? '' : '/f/' + id
}

async function load(): Promise<void> {
	try {
		forms.value = await listForms()
		loadFailed.value = false
	} catch {
		loadFailed.value = true
	} finally {
		loading.value = false
	}
}

onMounted(() => {
	window.addEventListener('hashchange', onHashChange)
	void load()
})

onBeforeUnmount(() => window.removeEventListener('hashchange', onHashChange))

async function create(): Promise<void> {
	creating.value = true
	createFailed.value = false
	try {
		const form = await createForm(t('advancedforms', 'Untitled form'))
		forms.value = [form, ...forms.value]
		open(form.id)
	} catch {
		createFailed.value = true
	} finally {
		creating.value = false
	}
}

/** Keep the list in step with edits made in the builder (title, status). */
function onChanged(form: OwnerForm): void {
	forms.value = forms.value.map((existing) => (existing.id === form.id ? { ...existing, ...form } : existing))
}

function onDeleted(): void {
	forms.value = forms.value.filter((form) => form.id !== selectedId.value)
	open(null)
}

function statusText(form: OwnerForm): string {
	switch (form.status) {
		case FORM_STATUS_PUBLISHED: return t('advancedforms', 'Published')
		case FORM_STATUS_CLOSED: return t('advancedforms', 'Closed')
		default: return t('advancedforms', 'Draft')
	}
}
</script>

<template>
	<NcContent app-name="advancedforms">
		<NcAppNavigation :aria-label="t('advancedforms', 'Forms')">
			<template #list>
				<NcAppNavigationNew :text="t('advancedforms', 'New form')"
					:disabled="creating"
					@click="create">
					<template #icon>
						<NcLoadingIcon v-if="creating" :size="20" />
						<span v-else aria-hidden="true" class="af-new-plus">+</span>
					</template>
				</NcAppNavigationNew>
				<NcAppNavigationList>
					<NcAppNavigationItem v-for="form in forms"
						:key="form.id"
						:name="form.title"
						:active="form.id === selectedId"
						:href="'#/f/' + form.id"
						:details="statusText(form)"
						@click="open(form.id)" />
				</NcAppNavigationList>
				<NcNoteCard v-if="loadFailed" type="error">
					{{ t('advancedforms', 'Could not load your forms.') }}
					<NcButton variant="tertiary" @click="load">
						{{ t('advancedforms', 'Try again') }}
					</NcButton>
				</NcNoteCard>
				<NcNoteCard v-if="createFailed" type="error">
					{{ t('advancedforms', 'Could not create the form.') }}
				</NcNoteCard>
			</template>
		</NcAppNavigation>

		<EntriesView v-if="selectedId !== null && view === 'responses'"
			:key="'responses-' + selectedId"
			:form-id="selectedId"
			:title="selectedTitle" />

		<BuilderView v-else-if="selectedId !== null"
			:key="selectedId"
			:form-id="selectedId"
			:view="view === 'settings' ? 'settings' : 'edit'"
			@changed="onChanged"
			@deleted="onDeleted" />

		<NcAppContent v-else>
			<div class="af-home">
				<NcLoadingIcon v-if="loading" :size="44" />
				<NcEmptyContent v-else-if="forms.length === 0"
					:name="t('advancedforms', 'Create your first form')"
					:description="t('advancedforms', 'Build forms with conditional fields and flexible layouts. Responses stay on your server.')">
					<template #action>
						<NcButton variant="primary" :disabled="creating" @click="create">
							{{ t('advancedforms', 'New form') }}
						</NcButton>
					</template>
				</NcEmptyContent>
				<NcEmptyContent v-else
					:name="t('advancedforms', 'Select a form')"
					:description="t('advancedforms', 'Choose a form from the list to edit it, or create a new one.')" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.af-home {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}

.af-new-plus {
	font-size: 22px;
	line-height: 1;
}
</style>
