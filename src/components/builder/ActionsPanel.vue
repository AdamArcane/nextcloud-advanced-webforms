<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { MailPolicy } from '../../api/settings.ts'
import type { ActionType, DefinitionError, DraftDefinition, FormAction } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import { createWebhookSecret } from '../../api/forms.ts'
import { ACTION_TYPES, addAction, errorActionIndex, MAX_ACTIONS, moveAction, removeAction, unreachableResponseActions, updateAction } from '../../builder/actions.ts'
import { isDestinationAction } from '../../builder/destinations.ts'
import ActionCard from './ActionCard.vue'
import AddActionMenu from './AddActionMenu.vue'
import SettingsCard from './SettingsCard.vue'

const props = defineProps<{
	definition: DraftDefinition
	formId: number
	errors: DefinitionError[]
	/** Show problems on every card, e.g. after a failed publish. */
	showErrors?: boolean
	/** The action types the server allows (docs/SCHEMA.md section 16). Everything when not given. */
	allowedTypes?: readonly ActionType[]
	/** The most actions the server allows on a form, never above MAX_ACTIONS. */
	maxActions?: number
	/** The administrator's settings for mail, so an email action can say what applies. */
	mail?: MailPolicy
	/** An editor, not the owner: actions that use the owner's Files or Tables cannot be added or changed (docs/SCHEMA.md section 22.5). */
	ownerOnly?: boolean
}>()

const emit = defineEmits<{
	/** The whole definition with the actions changed. */
	change: [definition: DraftDefinition]
}>()

const expandedId = ref<string | null>(null)
const revealed = ref<{ actionId: string, secret: string } | null>(null)
const secretBusyId = ref<string | null>(null)
const secretFailedId = ref<string | null>(null)

const unreachable = computed(() => new Set(unreachableResponseActions(props.definition)))
const limit = computed(() => Math.min(MAX_ACTIONS, props.maxActions ?? MAX_ACTIONS))
const full = computed(() => props.definition.actions.length >= limit.value)
const choices = computed(() => ACTION_TYPES.filter((type) => props.allowedTypes === undefined || props.allowedTypes.includes(type)).filter((type) => !props.ownerOnly || !isDestinationAction({ type })))
/** Actions of a type the server has since switched off: they stay visible so they can be removed. */
const switchedOff = computed(() => new Set(props.definition.actions.filter((action) => props.allowedTypes !== undefined && !props.allowedTypes.includes(action.type)).map((action) => action.id)))

function codesFor(index: number): string[] {
	return props.errors.filter((error) => errorActionIndex(error.path) === index).map((error) => error.code)
}

function add(type: ActionType): void {
	const result = addAction(props.definition, type)
	if (result.actionId) {
		emit('change', result.definition)
		expandedId.value = result.actionId
	}
}

function toggle(id: string): void {
	expandedId.value = expandedId.value === id ? null : id
}

function update(index: number, action: FormAction): void {
	emit('change', updateAction(props.definition, index, action))
}

function remove(index: number): void {
	const id = props.definition.actions[index]?.id
	emit('change', removeAction(props.definition, index))
	if (id && expandedId.value === id) {
		expandedId.value = null
	}
	if (id && revealed.value?.actionId === id) {
		revealed.value = null
	}
}

async function generateSecret(index: number): Promise<void> {
	const action = props.definition.actions[index]
	if (!action || action.type !== 'webhook') {
		return
	}
	secretBusyId.value = action.id
	secretFailedId.value = null
	try {
		// Passing the existing reference replaces that secret, so the action keeps pointing at the new one.
		const created = await createWebhookSecret(props.formId, action.secretRef)
		revealed.value = { actionId: action.id, secret: created.secret }
		emit('change', updateAction(props.definition, index, { ...action, secretRef: created.ref }))
	} catch {
		secretFailedId.value = action.id
	} finally {
		secretBusyId.value = null
	}
}
</script>

<template>
	<div class="af-actions">
		<SettingsCard :title="t('webforms', 'After someone sends the form')"
			:description="t('webforms', 'Choose what happens next. Background actions run after the response is saved, so a slow mail server or website never holds up the respondent.')">
			<div class="af-actions__add">
				<AddActionMenu :types="choices" :disabled="full" @pick="add" />
				<p v-if="choices.length === 0" class="af-actions__hint">
					{{ t('webforms', 'An administrator has switched off every kind of action on this server.') }}
				</p>
				<p v-else-if="full" class="af-actions__hint">
					{{ t('webforms', 'A form can have up to {max} actions.', { max: limit }) }}
				</p>
				<p v-else-if="definition.actions.length > 0" class="af-actions__hint">
					{{ t('webforms', 'If several messages or redirects match, the first one is shown. Everything in the background runs.') }}
				</p>
			</div>
		</SettingsCard>

		<ActionCard v-for="(action, index) in definition.actions"
			:key="action.id"
			:action="action"
			:index="index"
			:count="definition.actions.length"
			:definition="definition"
			:expanded="expandedId === action.id"
			:error-codes="codesFor(index)"
			:show-errors="showErrors"
			:unreachable="unreachable.has(action.id)"
			:switched-off="switchedOff.has(action.id)"
			:owner-only="ownerOnly"
			:mail="mail"
			:revealed-secret="revealed?.actionId === action.id ? revealed.secret : null"
			:secret-busy="secretBusyId === action.id"
			:secret-failed="secretFailedId === action.id"
			@toggle="toggle(action.id)"
			@update="(next) => update(index, next)"
			@remove="remove(index)"
			@move="(by) => emit('change', moveAction(definition, index, by))"
			@generate-secret="generateSecret(index)"
			@dismiss-secret="revealed = null" />

		<p v-if="definition.actions.length === 0" class="af-actions__empty">
			{{ t('webforms', 'No actions yet. Respondents will see a default thank-you.') }}
		</p>

	</div>
</template>

<style scoped>
.af-actions {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 6);
}

.af-actions__intro,
.af-actions__empty,
.af-actions__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.af-actions__hint {
	font-size: 13px;
}

.af-actions__add {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}
</style>
