<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { OwnTemplate } from '../api/forms.ts'
import type { OwnerForm } from '../types.ts'

import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { createFromTemplate, deleteTemplate, fetchTemplates } from '../api/forms.ts'
import { ApiError } from '../api/http.ts'
import { describeError } from '../builder/errors.ts'
import { templateStrings, translateTemplateText } from '../templates/catalog.ts'

const props = defineProps<{
	open: boolean
	/** Whether the person may make new forms at all. */
	canCreate: boolean
}>()

const emit = defineEmits<{
	'update:open': [open: boolean]
	/** The person chose a blank form. The screen makes it as it always has. */
	blank: []
	/** A form was made from a template. The screen adds it to its list and opens it. */
	created: [form: OwnerForm]
}>()

/** One line to choose from: the name, and a second line that says what it is. */
interface Choice {
	/** `blank`, or the id of a template: `b:` a starter, `s:` made for everyone, `u:` the person's own. */
	id: string
	label: string
	hint: string
}

const BLANK_ID = 'blank'

const loading = ref(false)
const loadFailed = ref(false)
const choices = ref<Choice[]>([])
const selected = ref<Choice | null>(null)
const working = ref(false)
const error = ref<string | null>(null)
const title = ref('')

// On a phone the dialog is only as tall as what is in it and sits in the middle of the screen. NcDialog does that
// for its small size (up to 512 px wide), and a normal one would fill the whole screen with empty space.
const NARROW = '(max-width: 512px)'
const narrow = ref(false)
let query: MediaQueryList | null = null
function onQueryChange(event: MediaQueryListEvent): void {
	narrow.value = event.matches
}
onMounted(() => {
	query = typeof window.matchMedia === 'function' ? window.matchMedia(NARROW) : null
	narrow.value = query?.matches ?? false
	query?.addEventListener('change', onQueryChange)
})
onBeforeUnmount(() => query?.removeEventListener('change', onQueryChange))

const CATEGORY_ORDER = ['general', 'events', 'feedback', 'support']

const isOwn = computed(() => selected.value?.id.startsWith('u:') === true)
const canUse = computed(() => props.canCreate && !working.value && selected.value !== null)

function blankChoice(): Choice {
	return { id: BLANK_ID, label: t('webforms', 'Blank form'), hint: t('webforms', 'Start with nothing and add your own fields.') }
}

function byCategory(a: { category: string }, b: { category: string }): number {
	const rank = (category: string): number => {
		const index = CATEGORY_ORDER.indexOf(category)
		return index === -1 ? CATEGORY_ORDER.length : index
	}
	return rank(a.category) - rank(b.category)
}

function ownLine(item: OwnTemplate, mine: boolean): string {
	return mine
		? n('webforms', 'My template, %n field', 'My template, %n fields', item.fieldCount)
		: n('webforms', 'Shared template, %n field', 'Shared template, %n fields', item.fieldCount)
}

// Looked up each time the dialog opens, so a template saved a minute ago is there.
watch(() => props.open, async (open) => {
	if (!open) {
		return
	}
	error.value = null
	title.value = ''
	loadFailed.value = false
	loading.value = true
	choices.value = [blankChoice()]
	selected.value = choices.value[0]!
	try {
		const list = await fetchTemplates()
		choices.value = [
			blankChoice(),
			...(list.shared ?? []).map((item): Choice => ({ id: item.id, label: item.name, hint: ownLine(item, false) })),
			...list.own.map((item): Choice => ({ id: item.id, label: item.name, hint: ownLine(item, true) })),
			...[...list.bundled].sort(byCategory).map((item): Choice => ({
				id: item.id,
				label: translateTemplateText(item.name),
				hint: translateTemplateText(item.description),
			})),
		]
		selected.value = choices.value[0]!
	} catch {
		loadFailed.value = true
	} finally {
		loading.value = false
	}
}, { immediate: true })

function close(): void {
	emit('update:open', false)
}

async function create(): Promise<void> {
	const choice = selected.value
	if (choice === null || working.value) {
		return
	}
	if (choice.id === BLANK_ID) {
		close()
		emit('blank')
		return
	}
	working.value = true
	error.value = null
	try {
		// Only a starter template has its words translated; the others are used as they were saved.
		const strings = choice.id.startsWith('b:') ? templateStrings() : undefined
		const { form } = await createFromTemplate(choice.id, title.value.trim(), strings)
		close()
		emit('created', form)
	} catch (caught) {
		error.value = caught instanceof ApiError ? describeError(caught.errors[0]?.code ?? caught.code) : t('webforms', 'Could not make a form from this template.')
	} finally {
		working.value = false
	}
}

async function remove(): Promise<void> {
	const choice = selected.value
	if (choice === null || !choice.id.startsWith('u:')) {
		return
	}
	error.value = null
	try {
		await deleteTemplate(choice.id)
		choices.value = choices.value.filter((other) => other.id !== choice.id)
		selected.value = choices.value[0] ?? null
	} catch {
		error.value = t('webforms', 'Could not delete the template.')
	}
}
</script>

<template>
	<NcDialog :open="open"
		:name="t('webforms', 'New form')"
		:size="narrow ? 'small' : 'normal'"
		@update:open="(value: boolean) => emit('update:open', value)">
		<div class="af-tpl">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="loadFailed" type="warning">
				{{ t('webforms', 'The templates could not be loaded. You can still start with a blank form.') }}
			</NcNoteCard>
			<NcSelect v-model="selected"
				class="af-tpl__select"
				:input-label="t('webforms', 'Start from')"
				:options="choices"
				:loading="loading"
				:clearable="false"
				:searchable="false"
				:disabled="!canCreate"
				label="label">
				<template #option="option">
					<span class="af-tpl__option">
						<span class="af-tpl__name">{{ option.label }}</span>
						<span class="af-tpl__hint">{{ option.hint }}</span>
					</span>
				</template>
				<template #selected-option="option">
					<span class="af-tpl__option">
						<span class="af-tpl__name">{{ option.label }}</span>
						<span class="af-tpl__hint">{{ option.hint }}</span>
					</span>
				</template>
			</NcSelect>
			<NcTextField v-model="title"
				:label="t('webforms', 'Title (optional)')"
				:helper-text="t('webforms', 'Leave empty to use the name of the template.')" />
		</div>
		<template #actions>
			<NcButton v-if="isOwn"
				variant="tertiary"
				:disabled="working"
				@click="remove">
				{{ t('webforms', 'Delete template') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canUse" @click="create">
				{{ t('webforms', 'Create form') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.af-tpl {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding-bottom: 12px;
}

.af-tpl__select {
	width: 100%;
}

/* Room for two lines in the box, so the second line is not cut by the border. */
.af-tpl__select :deep(.vs__dropdown-toggle) {
	min-height: 64px;
	padding: 6px 4px;
}

.af-tpl__select :deep(.vs__selected-options) {
	align-items: center;
	padding: 0;
}

.af-tpl__select :deep(.vs__selected) {
	width: 100%;
	height: auto;
	min-height: 0;
	margin: 0;
	padding: 0 8px;
}

/* The same two lines in the list and in the box: the name, and under it what it is. */
.af-tpl__option {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	padding: 6px 0;
	line-height: 1.35;
}

.af-tpl__name {
	overflow: hidden;
	font-weight: bold;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.af-tpl__hint {
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
