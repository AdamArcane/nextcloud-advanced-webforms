<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { FormField } from '../../types.ts'
import type { UploadState } from './uploadState.ts'

import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, ref } from 'vue'
import { acceptAttribute, allowedExtensions, formatSize, maxBytes, maxFiles } from '../../uploads.ts'

const props = defineProps<{
	fieldId: string
	field: FormField
	uploads: UploadState
	signedIn: boolean
	preview: boolean
	/** The server's largest single upload, in bytes. */
	limit?: number
	describedBy?: string
	required: boolean
}>()

const domId = computed(() => 'af-' + props.fieldId)
const dragging = ref(false)

const items = computed(() => props.uploads.items(props.fieldId))
const notice = computed(() => props.uploads.notice(props.fieldId))
const limit = computed(() => maxFiles(props.field))
const single = computed(() => limit.value === 1)
const blocked = computed(() => props.field.audience === 'login' && !props.signedIn && !props.preview)
const loginUrl = computed(() => generateUrl('/login') + '?redirect_url=' + encodeURIComponent(window.location.pathname + window.location.search))
const full = computed(() => items.value.filter((item) => item.status !== 'error').length >= limit.value)

const hint = computed(() => {
	const extensions = allowedExtensions(props.field)
	const shown = extensions.slice(0, 8).map((extension) => extension.toUpperCase()).join(', ') + (extensions.length > 8 ? ', …' : '')
	const size = formatSize(maxBytes(props.field, props.limit))
	return single.value
		? t('webforms', 'One file, up to {size}. Accepted: {types}', { size, types: shown })
		: t('webforms', 'Up to {count} files, {size} each. Accepted: {types}', { count: limit.value, size, types: shown })
})

function onPick(event: Event) {
	const input = event.target as HTMLInputElement
	props.uploads.add(props.fieldId, props.field, [...(input.files ?? [])])
	// Picking the same file again must still fire a change.
	input.value = ''
}

function onDrop(event: DragEvent) {
	dragging.value = false
	if (!blocked.value) {
		props.uploads.add(props.fieldId, props.field, [...(event.dataTransfer?.files ?? [])])
	}
}
</script>

<template>
	<div class="af-file"
		:class="{ 'af-file--drag': dragging, 'af-file--disabled': blocked }"
		@dragover.prevent="dragging = true"
		@dragleave="dragging = false"
		@drop.prevent="onDrop">
		<p v-if="blocked" class="af-file__login">
			{{ t('webforms', 'You need to sign in to upload files here.') }}
			<a :href="loginUrl">{{ t('webforms', 'Sign in') }}</a>
		</p>
		<template v-else>
			<input :id="domId"
				class="af-file__input"
				type="file"
				:multiple="!single"
				:accept="acceptAttribute(field)"
				:disabled="full && !single"
				:aria-required="required || undefined"
				:aria-describedby="describedBy"
				@change="onPick">
			<label :for="domId" class="af-file__drop">
				<span class="af-file__action">{{ single ? t('webforms', 'Choose a file') : t('webforms', 'Choose files') }}</span>
				<span class="af-file__or">{{ t('webforms', 'or drop here') }}</span>
			</label>
			<p class="af-file__hint">
				{{ hint }}
			</p>
		</template>

		<ul v-if="items.length > 0" class="af-file__list">
			<li v-for="item in items" :key="item.key" class="af-file__item" :class="{ 'af-file__item--error': item.status === 'error' }">
				<span class="af-file__name">{{ item.name }}</span>
				<span class="af-file__size">{{ formatSize(item.size) }}</span>
				<progress v-if="item.status === 'uploading'"
					class="af-file__progress"
					max="1"
					:value="item.progress"
					:aria-label="t('webforms', 'Uploading {name}', { name: item.name })" />
				<span v-else-if="item.status === 'done'" class="af-file__done" role="img" :aria-label="t('webforms', 'Uploaded')">✓</span>
				<button type="button"
					class="af-file__remove"
					:aria-label="t('webforms', 'Remove {name}', { name: item.name })"
					:title="t('webforms', 'Remove')"
					@click="uploads.remove(fieldId, item)">
					×
				</button>
				<span v-if="item.error" class="af-file__problem" role="alert">{{ item.error }}</span>
			</li>
		</ul>
		<p v-if="notice" class="af-file__problem" role="alert">
			{{ notice }}
		</p>
	</div>
</template>

<style scoped>
.af-file {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.af-file__input {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
}

.af-file__drop {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: center;
	gap: 4px 12px;
	padding: 16px;
	border: 2px dashed var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	cursor: pointer;
	text-align: center;
}

.af-file__drop:hover,
.af-file--drag .af-file__drop {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.af-file__input:focus-visible + .af-file__drop {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.af-file__action {
	font-weight: bold;
	color: var(--color-primary-element);
}

.af-file__or,
.af-file__hint,
.af-file__size {
	color: var(--color-text-maxcontrast);
}

.af-file__hint {
	margin: 0;
	font-size: 13px;
}

.af-file__login {
	margin: 0;
	padding: 12px 16px;
	border: 2px dashed var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
}

.af-file__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.af-file__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 12px;
	padding: 6px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
}

.af-file__item--error {
	border-color: var(--color-error);
}

.af-file__name {
	flex: 1 1 8rem;
	min-width: 0;
	overflow-wrap: anywhere;
}

.af-file__progress {
	width: 80px;
}

.af-file__done {
	color: var(--color-success-text, var(--color-success));
}

.af-file__remove {
	min-height: 0;
	padding: 0 8px;
	border: 0;
	background: none;
	color: var(--color-text-maxcontrast);
	font-size: 20px;
	line-height: 1.4;
	cursor: pointer;
}

.af-file__remove:hover {
	color: var(--color-main-text);
}

.af-file__problem {
	flex-basis: 100%;
	margin: 0;
	color: var(--color-error-text, var(--color-error));
	font-size: 13px;
}
</style>
