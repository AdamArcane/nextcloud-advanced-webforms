<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { OwnTemplate } from '../api/forms.ts'
import type { OwnerForm } from '../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { saveAsTemplate } from '../api/forms.ts'
import { ApiError } from '../api/http.ts'
import { describeError } from '../builder/errors.ts'

const props = defineProps<{
	open: boolean
	/** The form to keep as a template. */
	form: OwnerForm | null
}>()

const emit = defineEmits<{
	'update:open': [open: boolean]
	saved: [template: OwnTemplate]
}>()

// Same limits as the server (FormService): a name of up to 255 characters, a description of up to 500.
const NAME_MAX = 255
const DESCRIPTION_MAX = 500

const name = ref('')
const description = ref('')
const working = ref(false)
const error = ref<string | null>(null)

// On a phone the dialog is as short as it can be: the text above the fields is left out there.
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

/** The form's title and the word template, cut so the whole name stays within the limit. */
function suggestedName(form: OwnerForm): string {
	const suffix = t('webforms', '{title} template', { title: '' })
	const title = form.title.trim().slice(0, Math.max(0, NAME_MAX - suffix.length))
	return t('webforms', '{title} template', { title })
}

// Fresh each time the dialog opens, so a second template is not named after the first. There is no default
// description: an empty one means none, and the placeholder says what to write.
watch(() => props.open, (open) => {
	if (!open || props.form === null) {
		return
	}
	name.value = suggestedName(props.form)
	description.value = ''
	error.value = null
	working.value = false
})

const nameProblem = computed(() => {
	if (name.value.trim() === '') {
		return t('webforms', 'The template needs a name.')
	}
	return name.value.length > NAME_MAX ? t('webforms', 'The name is too long.') : null
})
const descriptionProblem = computed(() => description.value.length > DESCRIPTION_MAX ? t('webforms', 'The template description is too long.') : null)
const canSave = computed(() => props.form !== null && !working.value && nameProblem.value === null && descriptionProblem.value === null)

async function save(): Promise<void> {
	if (props.form === null || !canSave.value) {
		return
	}
	working.value = true
	error.value = null
	try {
		const template = await saveAsTemplate(props.form.id, name.value.trim(), description.value.trim())
		emit('saved', template)
		emit('update:open', false)
	} catch (caught) {
		error.value = caught instanceof ApiError
			? describeError(caught.errors[0]?.code ?? caught.code)
			: t('webforms', 'Could not save the template.')
	} finally {
		working.value = false
	}
}
</script>

<template>
	<NcDialog :open="open"
		:name="t('webforms', 'Save as template')"
		size="small"
		@update:open="(value: boolean) => emit('update:open', value)">
		<div class="af-save-tpl">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<p v-if="!narrow" class="af-save-tpl__hint">
				{{ t('webforms', 'Keeps a copy of the form as it is now. Only you can see your templates. Webhook secrets are never copied.') }}
			</p>
			<NcTextField v-model="name"
				:label="t('webforms', 'Name')"
				:maxlength="NAME_MAX"
				:error="nameProblem !== null"
				:helper-text="nameProblem ?? ''" />
			<NcTextArea v-model="description"
				:label="t('webforms', 'Description (optional)')"
				:rows="3"
				:maxlength="DESCRIPTION_MAX"
				:error="descriptionProblem !== null"
				:placeholder="t('webforms', 'What is this template for?')"
				:helper-text="descriptionProblem ?? ''" />
		</div>
		<template #actions>
			<NcButton variant="tertiary" :disabled="working" @click="emit('update:open', false)">
				{{ t('webforms', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSave" @click="save">
				{{ t('webforms', 'Save template') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.af-save-tpl {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding-bottom: 12px;
}

.af-save-tpl__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
