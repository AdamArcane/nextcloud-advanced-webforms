<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { TemplateAdminSettings } from '../../api/admin.ts'
import type { OwnerForm } from '../../types.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'
import { addSharedTemplate, deleteSharedTemplate, fetchAdminTemplates, renameSharedTemplate, saveHiddenTemplates } from '../../api/admin.ts'
import { listForms } from '../../api/forms.ts'
import { ApiError } from '../../api/http.ts'
import { describeError } from '../../builder/errors.ts'
import { translateTemplateText } from '../../templates/catalog.ts'

// Which starter templates everyone sees, and the templates made for everyone (docs/SCHEMA.md section 13.4).

const loaded = ref(false)
const failed = ref(false)
const saving = ref(false)
const settings = ref<TemplateAdminSettings>({ bundled: [], shared: [] })
/** The ids of the starter templates that are shown. */
const shown = ref<string[]>([])
const forms = ref<OwnerForm[]>([])
const formId = ref<number | null>(null)
const title = ref('')
const description = ref('')
/** The new name typed for a shared template, by id. */
const names = ref<Record<string, string>>({})
/** The description typed for a shared template, by id. */
const descriptions = ref<Record<string, string>>({})

const hiddenChanged = computed(() => {
	const was = settings.value.bundled.filter((item) => !item.hidden).map((item) => item.id).sort().join(',')
	return was !== [...shown.value].sort().join(',')
})

function apply(next: TemplateAdminSettings): void {
	settings.value = next
	shown.value = next.bundled.filter((item) => !item.hidden).map((item) => item.id)
	names.value = Object.fromEntries(next.shared.map((item) => [item.id, item.name]))
	descriptions.value = Object.fromEntries(next.shared.map((item) => [item.id, item.description]))
}

onMounted(async () => {
	try {
		apply(await fetchAdminTemplates())
	} catch {
		failed.value = true
	}
	try {
		forms.value = await listForms()
	} catch {
		// Without the list nothing can be added, but the rest still works.
	}
	loaded.value = true
})

function problem(error: unknown): string {
	return error instanceof ApiError ? describeError(error.errors[0]?.code ?? error.code) : t('webforms', 'Could not save the templates.')
}

async function run(action: () => Promise<TemplateAdminSettings>, done: string): Promise<boolean> {
	saving.value = true
	try {
		apply(await action())
		showSuccess(done)
		return true
	} catch (error) {
		showError(problem(error))
		return false
	} finally {
		saving.value = false
	}
}

function saveShown(): Promise<boolean> {
	const hidden = settings.value.bundled.map((item) => item.id).filter((id) => !shown.value.includes(id))
	return run(() => saveHiddenTemplates(hidden), t('webforms', 'Settings saved'))
}

async function add(): Promise<void> {
	if (formId.value === null) {
		return
	}
	const id = formId.value
	if (await run(() => addSharedTemplate(id, title.value.trim(), description.value.trim() === '' ? null : description.value.trim()), t('webforms', 'Template added'))) {
		formId.value = null
		title.value = ''
		description.value = ''
	}
}

function rename(id: string): Promise<boolean> {
	return run(() => renameSharedTemplate(id, (names.value[id] ?? '').trim(), (descriptions.value[id] ?? '').trim()), t('webforms', 'Settings saved'))
}

function remove(id: string): Promise<boolean> {
	return run(() => deleteSharedTemplate(id), t('webforms', 'Template deleted'))
}
</script>

<template>
	<NcSettingsSection :name="t('webforms', 'Templates')"
		:description="t('webforms', 'The templates people can start a new form from. Hide starter templates you do not want, and add templates of your own for everyone.')">
		<NcNoteCard v-if="failed" type="error">
			{{ t('webforms', 'Could not load the templates.') }}
		</NcNoteCard>
		<div v-else-if="loaded" class="af-tpl-admin">
			<h3 class="af-tpl-admin__heading">
				{{ t('webforms', 'Starter templates') }}
			</h3>
			<p class="af-tpl-admin__hint">
				{{ t('webforms', 'Switched on templates are offered to everyone who can create forms. Forms already made from a hidden template are not affected.') }}
			</p>
			<NcCheckboxRadioSwitch v-for="item in settings.bundled"
				:key="item.id"
				v-model="shown"
				:value="item.id"
				type="checkbox">
				{{ translateTemplateText(item.name) }}
			</NcCheckboxRadioSwitch>
			<NcButton variant="primary" :disabled="saving || !hiddenChanged" @click="saveShown">
				{{ t('webforms', 'Save') }}
			</NcButton>

			<h3 class="af-tpl-admin__heading">
				{{ t('webforms', 'Shared templates') }}
			</h3>
			<p class="af-tpl-admin__hint">
				{{ t('webforms', 'Shown to everyone who can create forms. A template is a copy of one of your own forms as it is now: later changes to the form do not change it. Webhook secrets are never copied.') }}
			</p>
			<p v-if="settings.shared.length === 0" class="af-tpl-admin__hint">
				{{ t('webforms', 'There are no shared templates yet.') }}
			</p>
			<ul v-else class="af-tpl-admin__list">
				<li v-for="item in settings.shared" :key="item.id" class="af-tpl-admin__row">
					<NcTextField v-model="names[item.id]" :label="t('webforms', 'Name')" class="af-tpl-admin__name" />
					<NcTextField v-model="descriptions[item.id]" :label="t('webforms', 'Description')" class="af-tpl-admin__name" />
					<span class="af-tpl-admin__hint">{{ n('webforms', '%n field', '%n fields', item.fieldCount) }}</span>
					<NcButton variant="secondary"
						:disabled="saving || (names[item.id] ?? '').trim() === '' || (names[item.id] === item.name && (descriptions[item.id] ?? '') === item.description)"
						@click="rename(item.id)">
						{{ t('webforms', 'Save changes') }}
					</NcButton>
					<NcButton variant="tertiary"
						:aria-label="t('webforms', 'Delete the template {name}', { name: item.name })"
						:disabled="saving"
						@click="remove(item.id)">
						{{ t('webforms', 'Delete') }}
					</NcButton>
				</li>
			</ul>

			<label class="af-tpl-admin__label" for="af-tpl-admin-form">{{ t('webforms', 'Add from one of your forms') }}</label>
			<select id="af-tpl-admin-form" v-model="formId" class="af-tpl-admin__select">
				<option :value="null">
					{{ t('webforms', 'Choose a form') }}
				</option>
				<option v-for="form in forms" :key="form.id" :value="form.id">
					{{ form.title }}
				</option>
			</select>
			<NcTextField v-model="title"
				:label="t('webforms', 'Name (optional)')"
				:helper-text="t('webforms', 'Leave empty to use the name of the form.')" />
			<NcTextField v-model="description"
				:label="t('webforms', 'Description (optional)')"
				:helper-text="t('webforms', 'Shown under the name when people choose a template. Leave empty to use the description of the form.')" />
			<NcButton variant="primary" :disabled="saving || formId === null" @click="add">
				{{ t('webforms', 'Add template') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<style scoped>
.af-tpl-admin {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
	max-width: 640px;
}

.af-tpl-admin__heading {
	margin: 12px 0 0;
	font-size: 15px;
	font-weight: bold;
}

.af-tpl-admin__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.af-tpl-admin__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	width: 100%;
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-tpl-admin__row {
	display: flex;
	align-items: flex-end;
	flex-wrap: wrap;
	gap: 8px;
}

.af-tpl-admin__name {
	flex: 1 1 220px;
}

.af-tpl-admin__label {
	margin-top: 8px;
	font-weight: bold;
}

.af-tpl-admin__select {
	width: 100%;
	max-width: 400px;
}
</style>
