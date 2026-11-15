<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ActionType } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import { computed, ref } from 'vue'
import { actionHint, actionLabel } from '../../builder/labels.ts'
import { actionTypeIcons, icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'

const props = defineProps<{
	/** The types that may be added, in the order to show them. */
	types: readonly ActionType[]
	disabled?: boolean
}>()

const emit = defineEmits<{
	pick: [type: ActionType]
}>()

const open = ref(false)

/** Message and redirect share the one slot the respondent sees (usesResponseSlot). */
function onPage(type: ActionType): boolean {
	return type === 'message' || type === 'redirect'
}

/**
 * What the respondent sees right away, and what runs after the response is stored. The split is also
 * how they behave: the first matching message or redirect wins, every background action runs.
 */
const groups = computed(() => [
	{
		key: 'page',
		name: t('webforms', 'On the page'),
		types: props.types.filter((type) => onPage(type)),
	},
	{
		key: 'background',
		name: t('webforms', 'In the background'),
		types: props.types.filter((type) => !onPage(type)),
	},
].filter((group) => group.types.length > 0))

function pick(type: ActionType): void {
	open.value = false
	emit('pick', type)
}
</script>

<template>
	<NcPopover v-model:shown="open" placement="bottom-start">
		<template #trigger="{ attrs }">
			<NcButton v-bind="attrs"
				class="af-add-action__trigger"
				variant="primary"
				:disabled="disabled || types.length === 0">
				<template #icon>
					<Icon :path="icons.add" />
				</template>
				{{ t('webforms', 'Add an action') }}
			</NcButton>
		</template>
		<div class="af-add-action">
			<section v-for="group in groups" :key="group.key" class="af-add-action__group">
				<h3 class="af-add-action__heading">
					{{ group.name }}
				</h3>
				<ul class="af-add-action__list">
					<li v-for="type in group.types" :key="type">
						<button type="button" class="af-add-action__item" @click="pick(type)">
							<span class="af-add-action__icon">
								<Icon :path="actionTypeIcons[type]" :size="20" />
							</span>
							<span class="af-add-action__text">
								<span class="af-add-action__name">{{ actionLabel(type) }}</span>
								<span class="af-add-action__hint">{{ actionHint(type) }}</span>
							</span>
						</button>
					</li>
				</ul>
			</section>
		</div>
	</NcPopover>
</template>

<style scoped>
.af-add-action {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	width: min(400px, 90vw);
	max-height: min(520px, 70vh);
	overflow-y: auto;
	box-sizing: border-box;
	padding: calc(var(--default-grid-baseline) * 3);
}

.af-add-action__heading {
	margin: 0 0 var(--default-grid-baseline);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-weight: bold;
	letter-spacing: 0.04em;
	text-transform: uppercase;
}

.af-add-action__list {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-add-action__item {
	display: flex;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 3);
	width: 100%;
	padding: calc(var(--default-grid-baseline) * 2);
	border: none;
	border-radius: var(--border-radius-element);
	background: transparent;
	color: var(--color-main-text);
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.af-add-action__item:hover,
.af-add-action__item:focus-visible {
	background: var(--color-background-hover);
	outline: none;
}

.af-add-action__item:focus-visible {
	box-shadow: inset 0 0 0 2px var(--color-primary-element);
}

.af-add-action__icon {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border-radius: var(--border-radius-small);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.af-add-action__item:hover .af-add-action__icon,
.af-add-action__item:focus-visible .af-add-action__icon {
	color: var(--color-primary-element);
}

.af-add-action__text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.af-add-action__name {
	font-weight: bold;
}

.af-add-action__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	line-height: 1.4;
}
</style>
