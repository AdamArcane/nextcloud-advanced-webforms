<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition } from '../../types.ts'
import type { AllCondition, AnyCondition, Condition } from '../../rules/types.ts'
import type { SourceField } from '../../builder/rules.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { computed } from 'vue'
import { defaultLeaf, groupChildren, groupKind, isGroup, makeGroup, MAX_GROUP_DEPTH } from '../../builder/rules.ts'
import ConditionRow from './ConditionRow.vue'
import SegmentedControl from './SegmentedControl.vue'

// Groups nest, so this component renders itself for child groups.
defineOptions({ name: 'ConditionGroup' })

const props = defineProps<{
	node: AllCondition | AnyCondition
	/** The root group of a rule is depth 1. */
	depth: number
	definition: DraftDefinition
	sources: SourceField[]
	forbidden: ReadonlySet<string>
	/** Whether this group may be removed (never the root). */
	removable: boolean
}>()

const emit = defineEmits<{
	update: [node: AllCondition | AnyCondition]
	remove: []
}>()

const kind = computed(() => groupKind(props.node))
const children = computed(() => groupChildren(props.node))
const firstAllowed = computed(() => props.sources.find((source) => !props.forbidden.has(source.id)))
const canAddGroup = computed(() => props.depth < MAX_GROUP_DEPTH && firstAllowed.value !== undefined)

function commit(next: Condition[]): void {
	emit('update', makeGroup(kind.value, next))
}

function setKind(value: string | number): void {
	emit('update', makeGroup(value === 'any' ? 'any' : 'all', children.value))
}

function replace(index: number, child: Condition): void {
	commit(children.value.map((existing, i) => (i === index ? child : existing)))
}

function removeAt(index: number): void {
	// A group left with nothing is invalid, so removing its last member removes the group itself.
	if (children.value.length === 1 && props.removable) {
		emit('remove')
		return
	}
	commit(children.value.filter((_, i) => i !== index))
}

function addCondition(): void {
	if (firstAllowed.value) {
		commit([...children.value, defaultLeaf(props.definition, firstAllowed.value.id)])
	}
}

function addGroup(): void {
	if (firstAllowed.value) {
		// A group inside "all" is usually an "any" (and the reverse), which is what makes nesting useful.
		const inner = makeGroup(kind.value === 'all' ? 'any' : 'all', [defaultLeaf(props.definition, firstAllowed.value.id)])
		commit([...children.value, inner])
	}
}
</script>

<template>
	<div class="af-group" :class="{ 'af-group--nested': depth > 1 }">
		<div class="af-group__head">
			<span class="af-group__lead">{{ t('advancedforms', 'Match') }}</span>
			<SegmentedControl :model-value="kind"
				class="af-group__kind"
				:group-label="t('advancedforms', 'Match all or any of the conditions')"
				:options="[
					{ value: 'all', label: t('advancedforms', 'All') },
					{ value: 'any', label: t('advancedforms', 'Any') },
				]"
				@update:model-value="setKind" />
			<NcButton v-if="removable"
				variant="tertiary"
				:aria-label="t('advancedforms', 'Remove group')"
				@click="emit('remove')">
				✕
			</NcButton>
		</div>

		<ul class="af-group__list">
			<li v-for="(child, index) in children" :key="index" class="af-group__item">
				<span v-if="index > 0" class="af-group__joiner">
					{{ kind === 'all' ? t('advancedforms', 'and') : t('advancedforms', 'or') }}
				</span>
				<ConditionGroup v-if="isGroup(child)"
					:node="child"
					:depth="depth + 1"
					:definition="definition"
					:sources="sources"
					:forbidden="forbidden"
					removable
					@update="(next) => replace(index, next)"
					@remove="removeAt(index)" />
				<ConditionRow v-else
					:leaf="child"
					:definition="definition"
					:sources="sources"
					:forbidden="forbidden"
					:removable="children.length > 1 || removable"
					@update="(next) => replace(index, next)"
					@remove="removeAt(index)" />
			</li>
		</ul>

		<div class="af-group__add">
			<NcButton variant="tertiary" :disabled="!firstAllowed" @click="addCondition">
				{{ t('advancedforms', 'Add condition') }}
			</NcButton>
			<NcButton v-if="depth < MAX_GROUP_DEPTH" variant="tertiary" :disabled="!canAddGroup" @click="addGroup">
				{{ t('advancedforms', 'Add group') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.af-group {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.af-group--nested {
	padding: calc(var(--default-grid-baseline) * 2);
	border: 1px solid var(--color-border);
	border-inline-start: 3px solid var(--color-primary-element);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.af-group__head {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.af-group__lead {
	font-weight: bold;
}

.af-group__kind {
	flex: 1 1 auto;
	max-width: 160px;
}

.af-group__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-group__item {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.af-group__joiner {
	align-self: flex-start;
	padding: 0 calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	line-height: 20px;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.af-group__add {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}
</style>
