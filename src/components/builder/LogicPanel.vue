<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DefinitionError, DraftDefinition } from '../../types.ts'
import type { Rule } from '../../rules/types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { computed } from 'vue'
import { dependentsOf, errorRuleIndex, newRule, rulesTargeting } from '../../builder/rules.ts'
import RuleCard from './RuleCard.vue'

const props = defineProps<{
	definition: DraftDefinition
	fieldId: string
	errors: DefinitionError[]
}>()

const emit = defineEmits<{
	/** The complete, updated list of the form's rules. */
	rules: [rules: Rule[]]
	select: [fieldId: string]
}>()

const entries = computed(() => rulesTargeting(props.definition, props.fieldId))
const dependents = computed(() => dependentsOf(props.definition, props.fieldId))
const draftRule = computed(() => newRule(props.definition, props.fieldId))

function labelOf(id: string): string {
	const field = props.definition.fields[id]
	return field?.label || field?.text || t('webforms', 'Untitled field')
}

function codesFor(index: number): string[] {
	return props.errors.filter((error) => errorRuleIndex(error.path) === index).map((error) => error.code)
}

function add(): void {
	if (draftRule.value) {
		emit('rules', [...props.definition.rules, draftRule.value])
	}
}

function update(index: number, rule: Rule): void {
	emit('rules', props.definition.rules.map((existing, i) => (i === index ? rule : existing)))
}

function remove(index: number): void {
	emit('rules', props.definition.rules.filter((_, i) => i !== index))
}

function removeFromField(index: number): void {
	emit('rules', props.definition.rules.map((rule, i) => (i === index
		? { ...rule, targets: rule.targets.filter((id) => id !== props.fieldId) }
		: rule)))
}
</script>

<template>
	<div class="af-logic">
		<p class="af-logic__intro">
			{{ t('webforms', 'Show, hide or require this field depending on the answers to other fields.') }}
		</p>

		<RuleCard v-for="{ rule, index } in entries"
			:key="rule.id"
			:rule="rule"
			:definition="definition"
			:field-id="fieldId"
			:error-codes="codesFor(index)"
			@update="(next) => update(index, next)"
			@remove="remove(index)"
			@remove-from-field="removeFromField(index)" />

		<p v-if="entries.length === 0" class="af-logic__empty">
			{{ t('webforms', 'No rules yet. This field is always shown.') }}
		</p>
		<p v-else class="af-logic__hint">
			{{ t('webforms', 'With several "show" rules the field appears when any of them matches. A "hide" rule always wins.') }}
		</p>

		<div class="af-logic__add">
			<NcButton variant="secondary" :disabled="!draftRule" @click="add">
				{{ t('webforms', 'Add rule') }}
			</NcButton>
			<p v-if="!draftRule" class="af-logic__hint">
				{{ t('webforms', 'Add another input field first. Rules react to the answers in other fields.') }}
			</p>
		</div>

		<section v-if="dependents.length > 0" class="af-logic__deps">
			<h3 class="af-logic__heading">
				{{ t('webforms', 'This field\'s answer controls') }}
			</h3>
			<div class="af-logic__chips">
				<NcButton v-for="id in dependents"
					:key="id"
					variant="tertiary"
					@click="emit('select', id)">
					{{ labelOf(id) }}
				</NcButton>
			</div>
		</section>
	</div>
</template>

<style scoped>
.af-logic {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 6);
}

.af-logic__intro,
.af-logic__empty,
.af-logic__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.af-logic__hint {
	font-size: 13px;
}

.af-logic__add {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	align-items: flex-start;
}

.af-logic__heading {
	margin: 0 0 var(--default-grid-baseline);
	font-size: 14px;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.af-logic__chips {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}
</style>
