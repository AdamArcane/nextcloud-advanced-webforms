<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { PolicyAdminSettings, PolicyGroup } from '../../api/admin.ts'
import type { ActionType } from '../../types.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref, watch } from 'vue'
import { fetchPolicy, fetchRetentionPreview, savePolicy, searchGroups } from '../../api/admin.ts'
import { ApiError } from '../../api/http.ts'
import { actionHint, actionLabel } from '../../builder/labels.ts'

const loaded = ref(false)
const failed = ref(false)
const saving = ref(false)

const allTypes = ref<string[]>([])
const actionTypes = ref<string[]>([])
const retentionOn = ref(false)
const retentionDays = ref('90')
const maxResponses = ref('0')
const limitResponses = ref(false)
const maxActions = ref('10')
const ceiling = ref(10)
const everyone = ref(true)
const groups = ref<PolicyGroup[]>([])
const groupOptions = ref<PolicyGroup[]>([])
const groupsLoading = ref(false)

/** What retention would delete right now, shown before it is turned on. */
const due = ref<number | null>(null)
/** Set when the server wants an explicit yes before deleting existing responses. */
const confirmDue = ref<number | null>(null)

const days = computed(() => Number.parseInt(retentionDays.value, 10))
const daysValid = computed(() => Number.isInteger(days.value) && days.value >= 1 && days.value <= 3650)
const responsesValid = computed(() => !limitResponses.value || (Number.isInteger(Number.parseInt(maxResponses.value, 10)) && Number.parseInt(maxResponses.value, 10) >= 1))
const actionsValid = computed(() => {
	const value = Number.parseInt(maxActions.value, 10)
	return Number.isInteger(value) && value >= 1 && value <= ceiling.value
})
const missingGroups = computed(() => groups.value.filter((group) => group.exists === false))
const canSave = computed(() => (!retentionOn.value || daysValid.value) && responsesValid.value && actionsValid.value && (everyone.value || groups.value.length > 0))

function apply(policy: PolicyAdminSettings): void {
	allTypes.value = policy.allActionTypes
	actionTypes.value = [...policy.actionTypes]
	retentionOn.value = policy.retentionDays > 0
	retentionDays.value = String(policy.retentionDays > 0 ? policy.retentionDays : 90)
	limitResponses.value = policy.maxResponses > 0
	maxResponses.value = String(policy.maxResponses > 0 ? policy.maxResponses : 100)
	maxActions.value = String(policy.maxActions)
	ceiling.value = policy.maxActionsCeiling
	everyone.value = policy.creatorGroups.length === 0
	groups.value = policy.creatorGroups
	confirmDue.value = null
}

onMounted(async () => {
	try {
		apply(await fetchPolicy())
		await refreshDue()
	} catch {
		failed.value = true
	}
	loaded.value = true
})

async function refreshDue(): Promise<void> {
	due.value = null
	if (!retentionOn.value || !daysValid.value) {
		return
	}
	try {
		due.value = await fetchRetentionPreview(days.value)
	} catch {
		due.value = null
	}
}

watch([retentionOn, retentionDays], () => {
	confirmDue.value = null
	void refreshDue()
})

async function onGroupSearch(search: string): Promise<void> {
	groupsLoading.value = true
	try {
		groupOptions.value = await searchGroups(search)
	} catch {
		groupOptions.value = []
	} finally {
		groupsLoading.value = false
	}
}

function toggleType(type: string, on: boolean): void {
	actionTypes.value = on ? [...new Set([...actionTypes.value, type])] : actionTypes.value.filter((existing) => existing !== type)
}

async function save(confirmDelete = false): Promise<void> {
	saving.value = true
	try {
		apply(await savePolicy({
			actionTypes: actionTypes.value,
			retentionDays: retentionOn.value ? days.value : 0,
			maxResponses: limitResponses.value ? Number.parseInt(maxResponses.value, 10) : 0,
			maxActions: Number.parseInt(maxActions.value, 10),
			creatorGroups: everyone.value ? [] : groups.value.map((group) => group.id),
			confirmDelete,
		}))
		showSuccess(t('webforms', 'Settings saved'))
		await refreshDue()
	} catch (error) {
		if (error instanceof ApiError && error.code === 'confirmation_required') {
			confirmDue.value = Number(error.data.due ?? 0)
		} else if (error instanceof ApiError && error.code === 'unknown_group') {
			showError(t('webforms', 'One of the groups no longer exists. Remove it and try again.'))
		} else {
			showError(t('webforms', 'Could not save the settings.'))
		}
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<NcSettingsSection :name="t('webforms', 'Forms and responses')"
		:description="t('webforms', 'Server-wide rules for every form. The defaults keep everything open and keep responses for ever.')">
		<NcNoteCard v-if="failed" type="error">
			{{ t('webforms', 'Could not load the settings.') }}
		</NcNoteCard>

		<div v-else-if="loaded" class="af-policy">
			<!-- Action types -->
			<section class="af-policy__block">
				<h3>{{ t('webforms', 'Allowed actions') }}</h3>
				<p class="af-policy__hint">
					{{ t('webforms', 'What forms may do after someone sends them. A switched-off type is hidden in the builder, refused when publishing, and stops running on forms that are already live.') }}
				</p>
				<NcCheckboxRadioSwitch v-for="type in allTypes"
					:key="type"
					:model-value="actionTypes.includes(type)"
					@update:model-value="(on: boolean) => toggleType(type, on)">
					<strong>{{ actionLabel(type as ActionType) }}</strong>
					<span class="af-policy__description">{{ actionHint(type as ActionType) }}</span>
				</NcCheckboxRadioSwitch>
			</section>

			<!-- Retention -->
			<section class="af-policy__block">
				<h3>{{ t('webforms', 'Keep responses') }}</h3>
				<NcCheckboxRadioSwitch v-model="retentionOn" type="switch">
					{{ t('webforms', 'Delete responses automatically after a number of days') }}
				</NcCheckboxRadioSwitch>
				<template v-if="retentionOn">
					<NcTextField v-model="retentionDays"
						type="number"
						min="1"
						max="3650"
						:label="t('webforms', 'Days to keep a response')"
						:error="!daysValid"
						:helper-text="daysValid ? '' : t('webforms', 'Enter a whole number from 1 to 3650.')" />
					<NcNoteCard type="warning">
						{{ t('webforms', 'Responses older than this are deleted for good, with their uploaded files and delivery log. Copies already sent by email, webhook or to Tables are not touched.') }}
					</NcNoteCard>
					<p v-if="due !== null" class="af-policy__hint">
						{{ due === 0
							? t('webforms', 'No existing responses are older than this.')
							: t('webforms', '{count} existing responses are older than this and will be deleted.', { count: due }) }}
					</p>
				</template>
				<p v-else class="af-policy__hint">
					{{ t('webforms', 'Responses are kept until their owner deletes them.') }}
				</p>
			</section>

			<!-- Limits -->
			<section class="af-policy__block">
				<h3>{{ t('webforms', 'Limits per form') }}</h3>
				<NcCheckboxRadioSwitch v-model="limitResponses" type="switch">
					{{ t('webforms', 'Limit the number of responses') }}
				</NcCheckboxRadioSwitch>
				<NcTextField v-if="limitResponses"
					v-model="maxResponses"
					type="number"
					min="1"
					:label="t('webforms', 'Most responses a form accepts')"
					:error="!responsesValid"
					:helper-text="t('webforms', 'A form that reaches this shows its closed message. Two people sending at the same moment can go one over.')" />
				<NcTextField v-model="maxActions"
					type="number"
					min="1"
					:max="String(ceiling)"
					:label="t('webforms', 'Most actions on a form')"
					:error="!actionsValid"
					:helper-text="t('webforms', 'From 1 to {max}. Forms with more than this cannot be published.', { max: ceiling })" />
			</section>

			<!-- Creators -->
			<section class="af-policy__block">
				<h3>{{ t('webforms', 'Who can create forms') }}</h3>
				<NcCheckboxRadioSwitch v-model="everyone" type="radio" name="webforms-creators" :value="true">
					{{ t('webforms', 'Everyone') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="everyone" type="radio" name="webforms-creators" :value="false">
					{{ t('webforms', 'Only members of these groups, and administrators') }}
				</NcCheckboxRadioSwitch>
				<template v-if="!everyone">
					<NcSelect v-model="groups"
						multiple
						:options="groupOptions"
						:loading="groupsLoading"
						:filterable="false"
						label="displayName"
						:input-label="t('webforms', 'Groups')"
						:placeholder="t('webforms', 'Search for a group')"
						@search="onGroupSearch"
						@open="onGroupSearch('')" />
					<NcNoteCard v-if="groups.length === 0" type="warning">
						{{ t('webforms', 'Pick at least one group, or choose Everyone.') }}
					</NcNoteCard>
					<NcNoteCard v-if="missingGroups.length > 0" type="warning">
						{{ t('webforms', 'These groups no longer exist: {names}', { names: missingGroups.map((group) => group.id).join(', ') }) }}
					</NcNoteCard>
				</template>
				<p class="af-policy__hint">
					{{ t('webforms', 'Forms that already exist keep working and stay editable by their owners.') }}
				</p>
			</section>

			<NcNoteCard v-if="confirmDue !== null" type="warning">
				<p>
					<strong>{{ t('webforms', 'Saving will delete {count} existing responses older than {days} days, with their uploaded files. This cannot be undone.', { count: confirmDue, days }) }}</strong>
				</p>
				<div class="af-policy__confirm">
					<NcButton variant="error" :disabled="saving" @click="save(true)">
						{{ t('webforms', 'Delete them and save') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="saving" @click="confirmDue = null">
						{{ t('webforms', 'Cancel') }}
					</NcButton>
				</div>
			</NcNoteCard>

			<NcButton variant="primary" :disabled="saving || !canSave" @click="save(false)">
				{{ t('webforms', 'Save') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<style scoped>
.af-policy {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 4);
	max-width: 640px;
}

.af-policy__block {
	display: flex;
	flex-direction: column;
	align-items: stretch;
	gap: var(--default-grid-baseline);
	width: 100%;
}

.af-policy__block h3 {
	margin: 0;
}

.af-policy__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.af-policy__description {
	display: block;
	color: var(--color-text-maxcontrast);
}

.af-policy__confirm {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
	margin-top: var(--default-grid-baseline);
}
</style>
