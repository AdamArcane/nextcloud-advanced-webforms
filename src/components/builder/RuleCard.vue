<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition } from '../../types.ts'
import type { AllCondition, AnyCondition, Rule } from '../../rules/types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { computed } from 'vue'
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'
import { describeError } from '../../builder/errors.ts'
import { effectLabel } from '../../builder/labels.ts'
import { effectsFor, forbiddenSources, isGroup, makeGroup, sourceFields } from '../../builder/rules.ts'
import ConditionGroup from './ConditionGroup.vue'
import SegmentedControl from './SegmentedControl.vue'

const props = defineProps<{
	rule: Rule
	definition: DraftDefinition
	/** The field whose Logic tab this is shown in. */
	fieldId: string
	errorCodes: string[]
}>()

const emit = defineEmits<{
	update: [rule: Rule]
	/** Delete the whole rule. */
	remove: []
	/** Stop this rule applying to the current field, keeping it on its other targets. */
	removeFromField: []
}>()

const sources = computed(() => sourceFields(props.definition))
const forbidden = computed(() => forbiddenSources(props.definition, props.rule))
const effects = computed(() => effectsFor(props.definition.fields[props.fieldId]))

const otherTargets = computed(() => props.rule.targets
	.filter((id) => id !== props.fieldId)
	.map((id) => props.definition.fields[id]?.label || props.definition.fields[id]?.text || t('webforms', 'Untitled field')))

// A rule's condition should always be a group; wrap anything else so the editor can show it.
const root = computed<AllCondition | AnyCondition>(() => (isGroup(props.rule.when) ? props.rule.when : makeGroup('all', [props.rule.when])))

const sentence = computed(() => {
	switch (props.rule.effect) {
		case 'show': return t('webforms', 'This field is shown only when:')
		case 'hide': return t('webforms', 'This field is hidden when:')
		default: return t('webforms', 'This field is required when:')
	}
})
</script>

<template>
	<article class="af-rule" :class="{ 'af-rule--error': errorCodes.length > 0 }">
		<SegmentedControl :model-value="rule.effect"
			:group-label="t('webforms', 'What the rule does')"
			:options="effects.map((effect) => ({ value: effect, label: effectLabel(effect) }))"
			@update:model-value="(effect) => emit('update', { ...rule, effect: effect as Rule['effect'] })" />

		<p class="af-rule__sentence">
			{{ sentence }}
		</p>

		<ConditionGroup :node="root"
			:depth="1"
			:definition="definition"
			:sources="sources"
			:forbidden="forbidden"
			:removable="false"
			@update="(when) => emit('update', { ...rule, when })"
			@remove="emit('remove')">
			<template #actions>
				<NcButton variant="tertiary"
					class="af-rule__delete"
					:aria-label="otherTargets.length > 0 ? t('webforms', 'Delete rule for all fields') : t('webforms', 'Delete rule')"
					:title="otherTargets.length > 0 ? t('webforms', 'Delete rule for all fields') : t('webforms', 'Delete rule')"
					@click="emit('remove')">
					<template #icon>
						<Icon :path="icons.delete" />
					</template>
				</NcButton>
			</template>
		</ConditionGroup>

		<ul v-if="errorCodes.length > 0" class="af-rule__errors">
			<li v-for="code in errorCodes" :key="code">
				{{ describeError(code) }}
			</li>
		</ul>

		<p v-if="otherTargets.length > 0" class="af-rule__shared">
			{{ t('webforms', 'Also applies to: {fields}', { fields: otherTargets.join(', ') }) }}
		</p>

		<div v-if="otherTargets.length > 0" class="af-rule__footer">
			<NcButton variant="secondary" @click="emit('removeFromField')">
				<template #icon>
					<Icon :path="icons.unlink" />
				</template>
				{{ t('webforms', 'Stop applying to this field') }}
			</NcButton>
		</div>
	</article>
</template>

<style scoped>
.af-rule {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.af-rule--error {
	border-color: var(--color-error);
}

.af-rule__sentence {
	margin: 0;
	font-weight: bold;
}

.af-rule__errors {
	margin: 0;
	padding: 0;
	list-style: none;
	color: var(--color-error-text);
	font-size: 13px;
}

.af-rule__shared {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

/* The button lives in the slot of ConditionGroup, so the class is on a child component's root. */
.af-rule__delete {
	color: var(--color-element-error, var(--color-error)) !important;
}

.af-rule__delete:hover:not(:disabled) {
	background-color: var(--color-error) !important;
	color: var(--color-error-text-on-error, #fff) !important;
}

.af-rule__footer {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}
</style>
