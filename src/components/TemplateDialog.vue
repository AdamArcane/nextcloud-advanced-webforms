<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { BundledTemplate, OwnTemplate, SharedTemplate } from '../api/forms.ts'
import type { OwnerForm } from '../types.ts'

import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref, watch } from 'vue'
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

const loading = ref(false)
const loadFailed = ref(false)
const bundled = ref<BundledTemplate[]>([])
const own = ref<OwnTemplate[]>([])
const shared = ref<SharedTemplate[]>([])
const working = ref<string | null>(null)
const error = ref<string | null>(null)
const title = ref('')

const CATEGORY_ORDER = ['general', 'events', 'feedback', 'support']

/** Starter templates under their category, in a fixed order, with the words in the person's language. */
const groups = computed(() => {
	const known = new Set(CATEGORY_ORDER)
	const keys = [...CATEGORY_ORDER, ...new Set(bundled.value.map((item) => item.category).filter((key) => !known.has(key)))]
	return keys
		.map((key) => ({
			key,
			caption: categoryName(key),
			items: bundled.value.filter((item) => item.category === key),
		}))
		.filter((group) => group.items.length > 0)
})

function categoryName(key: string): string {
	return translateTemplateText(key.charAt(0).toUpperCase() + key.slice(1))
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
	try {
		const list = await fetchTemplates()
		bundled.value = list.bundled
		own.value = list.own
		shared.value = list.shared ?? []
	} catch {
		loadFailed.value = true
	} finally {
		loading.value = false
	}
}, { immediate: true })

function close(): void {
	emit('update:open', false)
}

function blank(): void {
	close()
	emit('blank')
}

async function use(id: string, isBundled: boolean): Promise<void> {
	if (working.value !== null) {
		return
	}
	working.value = id
	error.value = null
	try {
		const { form } = await createFromTemplate(id, title.value.trim(), isBundled ? templateStrings() : undefined)
		close()
		emit('created', form)
	} catch (caught) {
		error.value = caught instanceof ApiError ? describeError(caught.errors[0]?.code ?? caught.code) : t('webforms', 'Could not make a form from this template.')
	} finally {
		working.value = null
	}
}

async function remove(item: OwnTemplate): Promise<void> {
	error.value = null
	try {
		await deleteTemplate(item.id)
		own.value = own.value.filter((other) => other.id !== item.id)
	} catch {
		error.value = t('webforms', 'Could not delete the template.')
	}
}
</script>

<template>
	<NcDialog :open="open"
		:name="t('webforms', 'New form')"
		size="normal"
		@update:open="(value: boolean) => emit('update:open', value)">
		<div class="af-tpl">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcTextField v-model="title"
				:label="t('webforms', 'Title (optional)')"
				:helper-text="t('webforms', 'Leave empty to use the name of the template.')" />

			<section class="af-tpl__section">
				<ul class="af-tpl__grid">
					<li>
						<button type="button" class="af-tpl__tile" :disabled="!canCreate" @click="blank">
							<span class="af-tpl__name">{{ t('webforms', 'Blank form') }}</span>
							<span class="af-tpl__hint">{{ t('webforms', 'Start with nothing and add your own fields.') }}</span>
						</button>
					</li>
				</ul>
			</section>

			<NcLoadingIcon v-if="loading" :size="32" />
			<NcNoteCard v-else-if="loadFailed" type="warning">
				{{ t('webforms', 'The templates could not be loaded. You can still start with a blank form.') }}
			</NcNoteCard>
			<template v-else>
				<section v-if="shared.length > 0" class="af-tpl__section">
					<h3 class="af-tpl__heading">
						{{ t('webforms', 'Shared templates') }}
					</h3>
					<ul class="af-tpl__grid">
						<li v-for="item in shared" :key="item.id">
							<button type="button"
								class="af-tpl__tile"
								:disabled="!canCreate || working !== null"
								@click="use(item.id, false)">
								<span class="af-tpl__name">{{ item.name }}</span>
								<span class="af-tpl__hint">{{ n('webforms', '%n field', '%n fields', item.fieldCount) }}</span>
							</button>
						</li>
					</ul>
				</section>
				<section v-if="own.length > 0" class="af-tpl__section">
					<h3 class="af-tpl__heading">
						{{ t('webforms', 'My templates') }}
					</h3>
					<ul class="af-tpl__grid">
						<li v-for="item in own" :key="item.id" class="af-tpl__own">
							<button type="button"
								class="af-tpl__tile"
								:disabled="!canCreate || working !== null"
								@click="use(item.id, false)">
								<span class="af-tpl__name">{{ item.name }}</span>
								<span class="af-tpl__hint">{{ n('webforms', '%n field', '%n fields', item.fieldCount) }}</span>
							</button>
							<NcButton variant="tertiary"
								class="af-tpl__delete"
								:aria-label="t('webforms', 'Delete the template {name}', { name: item.name })"
								@click="remove(item)">
								{{ t('webforms', 'Delete') }}
							</NcButton>
						</li>
					</ul>
				</section>
				<section v-for="group in groups" :key="group.key" class="af-tpl__section">
					<h3 class="af-tpl__heading">
						{{ group.caption }}
					</h3>
					<ul class="af-tpl__grid">
						<li v-for="item in group.items" :key="item.id">
							<button type="button"
								class="af-tpl__tile"
								:disabled="!canCreate || working !== null"
								@click="use(item.id, true)">
								<span class="af-tpl__name">{{ translateTemplateText(item.name) }}</span>
								<span class="af-tpl__hint">{{ translateTemplateText(item.description) }}</span>
							</button>
						</li>
					</ul>
				</section>
			</template>
		</div>
	</NcDialog>
</template>

<style scoped>
.af-tpl {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-bottom: 12px;
}

.af-tpl__heading {
	margin: 8px 0 4px;
	font-size: 15px;
	font-weight: bold;
}

.af-tpl__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-tpl__tile {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
	width: 100%;
	height: 100%;
	padding: 10px 12px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.af-tpl__tile:hover:not(:disabled),
.af-tpl__tile:focus-visible {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.af-tpl__tile:disabled {
	opacity: 0.6;
	cursor: default;
}

.af-tpl__name {
	font-weight: bold;
}

.af-tpl__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.af-tpl__own {
	display: flex;
	flex-direction: column;
	gap: 2px;
}
</style>
