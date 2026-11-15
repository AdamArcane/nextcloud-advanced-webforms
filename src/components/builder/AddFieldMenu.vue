<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import { computed, nextTick, ref, watch } from 'vue'
import { FIELD_GROUPS, FIELD_TYPES } from '../../builder/definition.ts'
import { fieldGroupName, fieldTypeHint, fieldTypeName } from '../../builder/fieldTypeNames.ts'
import { fieldTypeIcon, icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'

defineProps<{
	/** Text on the trigger button. Without it the trigger is a compact plus. */
	label?: string
	variant?: 'primary' | 'secondary' | 'tertiary'
}>()

const emit = defineEmits<{
	pick: [type: string]
}>()

const open = ref(false)
const query = ref('')
const search = ref<HTMLInputElement | null>(null)

/** The groups with the types that match what was typed, in the order of FIELD_GROUPS. */
const groups = computed(() => {
	const needle = query.value.trim().toLowerCase()
	return FIELD_GROUPS
		.map((group) => ({
			group,
			name: fieldGroupName(group),
			types: FIELD_TYPES
				.filter((info) => info.group === group)
				.map((info) => ({ ...info, name: fieldTypeName(info.type), hint: fieldTypeHint(info.type) }))
				.filter((info) => needle === '' || (info.name + ' ' + info.hint + ' ' + info.label).toLowerCase().includes(needle)),
		}))
		.filter((entry) => entry.types.length > 0)
})

watch(open, async (isOpen) => {
	if (isOpen) {
		query.value = ''
		await nextTick()
		search.value?.focus()
	}
})

function pick(type: string) {
	open.value = false
	emit('pick', type)
}

/** Enter in the search box picks the first match, so typing a name and pressing Enter is enough. */
function pickFirst() {
	const first = groups.value[0]?.types[0]
	if (first) {
		pick(first.type)
	}
}
</script>

<template>
	<NcPopover v-model:shown="open"
		placement="bottom"
		:class="{ 'af-add-menu--compact': !label }">
		<template #trigger="{ attrs }">
			<NcButton v-bind="attrs"
				:variant="variant ?? 'tertiary'"
				:aria-label="label ? undefined : t('webforms', 'Add a field here')"
				:title="label ? undefined : t('webforms', 'Add a field here')">
				<template #icon>
					<Icon :path="icons.add" />
				</template>
				{{ label }}
			</NcButton>
		</template>
		<div class="af-add">
			<input ref="search"
				v-model="query"
				class="af-add__search"
				type="search"
				:placeholder="t('webforms', 'Search field types')"
				:aria-label="t('webforms', 'Search field types')"
				@keydown.enter.prevent="pickFirst">
			<div class="af-add__groups">
				<section v-for="entry in groups" :key="entry.group" class="af-add__group">
					<h3 class="af-add__heading">
						{{ entry.name }}
					</h3>
					<ul class="af-add__grid">
						<li v-for="info in entry.types" :key="info.type">
							<button type="button" class="af-add__item" :title="info.hint" @click="pick(info.type)">
								<span class="af-add__icon">
									<Icon :path="fieldTypeIcon(info.type)" :size="20" />
								</span>
								<span class="af-add__name">{{ info.name }}</span>
							</button>
						</li>
					</ul>
				</section>
				<p v-if="groups.length === 0" class="af-add__empty">
					{{ t('webforms', 'No field type matches that.') }}
				</p>
			</div>
		</div>
	</NcPopover>
</template>

<style scoped>
.af-add {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	width: min(360px, 90vw);
	max-height: min(480px, 70vh);
	box-sizing: border-box;
	padding: calc(var(--default-grid-baseline) * 3);
}

.af-add__search {
	width: 100%;
	box-sizing: border-box;
	margin: 0;
}

.af-add__groups {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	overflow-y: auto;
	/* Room for the focus ring of the items at the edge. */
	margin: calc(var(--default-grid-baseline) * -1);
	padding: var(--default-grid-baseline);
}

.af-add__heading {
	margin: 0 0 calc(var(--default-grid-baseline) * 1.5);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-weight: bold;
	letter-spacing: 0.04em;
	text-transform: uppercase;
}

.af-add__grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: var(--default-grid-baseline);
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-add__item {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	width: 100%;
	min-height: var(--default-clickable-area);
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2) var(--default-grid-baseline) var(--default-grid-baseline);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.af-add__item:hover,
.af-add__item:focus-visible {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
	outline: none;
}

.af-add__icon {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border-radius: var(--border-radius-small);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.af-add__item:hover .af-add__icon,
.af-add__item:focus-visible .af-add__icon {
	background: var(--color-main-background);
	color: var(--color-primary-element);
}

.af-add__name {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.af-add__empty {
	margin: 0;
	padding: calc(var(--default-grid-baseline) * 4) 0;
	color: var(--color-text-maxcontrast);
	text-align: center;
}
</style>
