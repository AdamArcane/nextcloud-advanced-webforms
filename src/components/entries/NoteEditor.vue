<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { EntryRecord } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { computed, ref, watch } from 'vue'
import { setEntryNote } from '../../api/forms.ts'

// The owner's private note on one response (docs/SCHEMA.md section 20.3).
const props = defineProps<{
	formId: number
	entry: EntryRecord
}>()

const emit = defineEmits<{
	/** The note was saved. Carries the response as the server now has it. */
	saved: [entry: EntryRecord]
}>()

const MAX = 4000

const text = ref(props.entry.note ?? '')
const saving = ref(false)
const failed = ref(false)
const justSaved = ref(false)

const changed = computed(() => text.value.trim() !== (props.entry.note ?? ''))
const tooLong = computed(() => text.value.trim().length > MAX)

// Another response is shown in the same place (stepping through them), or the note changed elsewhere.
watch(() => [props.entry.id, props.entry.note] as const, ([id], [previousId]) => {
	text.value = props.entry.note ?? ''
	if (id !== previousId) {
		failed.value = false
		justSaved.value = false
	}
})

async function save(): Promise<void> {
	saving.value = true
	failed.value = false
	justSaved.value = false
	try {
		const updated = await setEntryNote(props.formId, props.entry.id, text.value)
		emit('saved', updated)
		text.value = updated.note ?? ''
		justSaved.value = true
	} catch {
		failed.value = true
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<div class="af-note">
		<label class="af-note__label" :for="'af-note-' + entry.id">{{ t('webforms', 'Note') }}</label>
		<textarea :id="'af-note-' + entry.id"
			v-model="text"
			class="af-note__text"
			rows="3"
			:placeholder="t('webforms', 'Only you see this. It is never sent to the person who filled in the form.')"
			@input="justSaved = false" />
		<div class="af-note__row">
			<NcButton variant="secondary" :disabled="!changed || tooLong || saving" @click="save">
				{{ saving ? t('webforms', 'Saving…') : t('webforms', 'Save note') }}
			</NcButton>
			<span v-if="tooLong" class="af-note__state af-note__state--error" role="alert">
				{{ t('webforms', 'A note can be up to {max} characters.', { max: MAX }) }}
			</span>
			<span v-else-if="failed" class="af-note__state af-note__state--error" role="alert">
				{{ t('webforms', 'Could not save the note.') }}
			</span>
			<span v-else-if="justSaved" class="af-note__state" role="status">
				{{ t('webforms', 'Saved') }}
			</span>
		</div>
	</div>
</template>

<style scoped>
.af-note {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.af-note__label {
	font-size: 14px;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.af-note__text {
	width: 100%;
	box-sizing: border-box;
	margin: 0;
	resize: vertical;
}

.af-note__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

@media (max-width: 600px) {
	/* 16px keeps a phone browser from zooming in when the box is tapped. */
	.af-note__text {
		font-size: 16px;
	}
}

.af-note__state {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.af-note__state--error {
	color: var(--color-error-text);
}
</style>
