<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition, FormField } from '../../types.ts'
import type { Move } from '../../builder/definition.ts'

import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref, watch } from 'vue'
import { allowedSpans, canMove, locateField, MAX_FIELDS, SPANS } from '../../builder/definition.ts'
import { describeError } from '../../builder/errors.ts'
import { fieldIdProblem } from '../../builder/fieldId.ts'
import { DEFAULT_ACCEPT, DEFAULT_MAX_FILES, DEFAULT_MAX_SIZE_MB, UPLOAD_GROUPS } from '../../uploads.ts'
import { renderedLabelPosition, requestedLabelPosition, supportsInline } from '../../layout/labelPosition.ts'
import AddFieldMenu from './AddFieldMenu.vue'
import DefaultValueEditor from './DefaultValueEditor.vue'
import OptionsEditor from './OptionsEditor.vue'
import SegmentedControl from './SegmentedControl.vue'

const props = defineProps<{
	definition: DraftDefinition
	fieldId: string
	errorCodes: string[]
	/** Ids that cannot be reused (a published field had them). */
	retiredIds?: string[]
	/** Whether the form has been published, which makes an id change visible to receivers. */
	published?: boolean
}>()

const emit = defineEmits<{
	update: [patch: Partial<FormField>]
	span: [span: number]
	move: [move: Move]
	addBeside: [type: string]
	toPage: [pageIndex: number]
	rename: [id: string]
	remove: []
	duplicate: []
}>()

const field = computed(() => props.definition.fields[props.fieldId])
const type = computed(() => field.value?.type ?? '')

const isDisplay = computed(() => ['heading', 'paragraph', 'divider'].includes(type.value))
const hasPlaceholder = computed(() => ['text', 'textarea', 'email', 'url', 'number', 'phone', 'select'].includes(type.value))
const hasOptions = computed(() => ['select', 'radio', 'checkboxes'].includes(type.value))
const isHidden = computed(() => type.value === 'hidden')
const hasLabel = computed(() => !isDisplay.value && !isHidden.value)
/** Every input field except uploads has a default and can be filled from the link (docs/SCHEMA.md 4.7). */
const canPrefill = computed(() => !isDisplay.value && type.value !== 'file')
const prefillOff = computed(() => props.definition.settings.prefill === false)
const linkParameter = computed(() => '?' + props.fieldId + '=')
const copied = ref(false)

async function copyParameter(): Promise<void> {
	try {
		await navigator.clipboard.writeText(linkParameter.value)
		copied.value = true
		setTimeout(() => { copied.value = false }, 2000)
	} catch {
		copied.value = false
	}
}

// The segment shown: what this field sets itself, or "Form" when it follows the form-wide default.
const labelChoice = computed(() => {
	if (field.value?.labelPosition) {
		return field.value.labelPosition
	}
	return field.value?.hideLabel === true ? 'hidden' : 'default'
})
const labelOptions = computed(() => [
	{ value: 'default', label: t('webforms', 'Form'), icon: icons.labelForm },
	{ value: 'above', label: t('webforms', 'Above'), icon: icons.labelAbove },
	{
		value: 'inline',
		label: t('webforms', 'Beside'),
		icon: icons.labelBeside,
		disabled: !supportsInline(type.value),
		title: supportsInline(type.value) ? undefined : t('webforms', 'Not available for this type of field'),
	},
	{ value: 'hidden', label: t('webforms', 'Hidden'), icon: icons.labelHidden },
])

function positionName(position: string): string {
	switch (position) {
		case 'inline': return t('webforms', 'beside the field')
		case 'hidden': return t('webforms', 'hidden')
		default: return t('webforms', 'above the field')
	}
}

const labelHint = computed(() => {
	const rendered = renderedLabelPosition(field.value, props.definition.settings)
	if (labelChoice.value === 'default') {
		const formWide = requestedLabelPosition({ type: type.value }, props.definition.settings)
		if (formWide === 'inline' && rendered !== 'inline') {
			return t('webforms', 'Following the form setting. The label stays above this kind of field.')
		}
		return t('webforms', 'Following the form setting: {position}.', { position: positionName(rendered) })
	}
	if (labelChoice.value === 'hidden') {
		return t('webforms', 'Not shown, but still read by screen readers.')
	}
	if (labelChoice.value === 'inline') {
		return t('webforms', 'Moves above the field when there is not enough room.')
	}
	return t('webforms', 'Always above the field.')
})

/** Setting a position replaces the older hideLabel flag, so the two never disagree. */
const headingIcons = [icons.heading1, icons.heading2, icons.heading3, icons.heading4, icons.heading5, icons.heading6]
const columnIcons = [icons.columns1, icons.columns2, icons.columns3, icons.columns4]
const lineOptions = computed(() => [
	{ value: 'solid', label: t('webforms', 'Solid'), icon: icons.lineSolid },
	{ value: 'dashed', label: t('webforms', 'Dashed'), icon: icons.lineDashed },
	{ value: 'dotted', label: t('webforms', 'Dotted'), icon: icons.lineDotted },
])
const alignOptions = computed(() => [
	{ value: 'start', label: t('webforms', 'Left'), icon: icons.alignStart },
	{ value: 'center', label: t('webforms', 'Center'), icon: icons.alignCenter },
	{ value: 'end', label: t('webforms', 'Right'), icon: icons.alignEnd },
])

/** Start is the default, so it is stored as no value at all. */
function setAlign(choice: string): void {
	emit('update', { align: choice === 'center' || choice === 'end' ? choice : undefined })
}

function setLabelPosition(choice: string): void {
	emit('update', {
		labelPosition: choice === 'default' ? undefined : choice as FormField['labelPosition'],
		hideLabel: undefined,
	})
}

// Changing the id is explicit (Change ID), not per keystroke: every change rewrites rules, actions and layout.
const idDraft = ref(props.fieldId)
// The id can change under you when it follows the label.
watch(() => props.fieldId, (id) => { idDraft.value = id })
const idProblem = computed(() => fieldIdProblem(props.definition, props.fieldId, idDraft.value, props.retiredIds ?? []))
const idMessage = computed(() => {
	switch (idProblem.value) {
		case 'pattern': return t('webforms', 'Use 3 to 32 lowercase letters, numbers or underscores, starting with a letter.')
		case 'taken': return t('webforms', 'Another field already uses this ID.')
		case 'retired': return t('webforms', 'A published field used this ID before it was deleted, so it cannot be used again.')
		default: return null
	}
})

function applyId(): void {
	if (idProblem.value === null) {
		emit('rename', idDraft.value)
	}
}

const spanLabels: Record<number, string> = { 3: '¼', 4: '⅓', 6: '½', 8: '⅔', 9: '¾', 12: t('webforms', 'Full') }
const allowed = computed(() => allowedSpans(props.definition, props.fieldId))
const spanOptions = computed(() => SPANS.map((span) => ({
	value: span as number,
	label: spanLabels[span],
	disabled: !allowed.value.includes(span),
	title: allowed.value.includes(span) ? undefined : t('webforms', 'Not enough room in this row'),
})))
const currentSpan = computed(() => locateField(props.definition, props.fieldId)?.cell.span ?? null)
const canShareRow = computed(() => allowed.value.length > 0 && (locateField(props.definition, props.fieldId)?.row.cells.length ?? 0) >= 1)
const roomBeside = computed(() => {
	const at = locateField(props.definition, props.fieldId)
	return at !== null && at.row.cells.reduce((sum, cell) => sum + cell.span, 0) <= 9
})

function text(key: keyof FormField, value: string | number): void {
	const str = String(value)
	emit('update', { [key]: str === '' ? undefined : str } as Partial<FormField>)
}

function number(key: keyof FormField, value: string | number): void {
	const str = String(value).trim()
	const parsed = Number(str)
	emit('update', { [key]: str === '' || Number.isNaN(parsed) ? undefined : parsed } as Partial<FormField>)
}

function setRows(value: string | number): void {
	const parsed = Math.round(Number(String(value).trim()))
	// Five is the default, so it is stored as no value.
	emit('update', { rows: String(value).trim() === '' || Number.isNaN(parsed) || parsed === 5 ? undefined : Math.min(20, Math.max(2, parsed)) })
}

// Upload fields (docs/SCHEMA.md 4.6)
const groupLabels: Record<string, string> = {
	images: t('webforms', 'Images'),
	documents: t('webforms', 'Documents'),
	archives: t('webforms', 'Archives (zip, 7z)'),
	audio: t('webforms', 'Audio'),
	video: t('webforms', 'Video'),
}
const accepted = computed(() => field.value?.accept && field.value.accept.length > 0 ? field.value.accept : DEFAULT_ACCEPT)
const extraExtensions = computed(() => accepted.value.filter((entry) => entry.startsWith('.')).join(', '))
const uploadStorage = computed(() => field.value?.storage ?? 'files')

function toggleGroup(group: string, on: boolean) {
	const next = accepted.value.filter((entry) => entry !== group)
	if (on) {
		next.push(group)
	}
	// Nothing accepted would mean the default, so the last choice cannot be switched off.
	emit('update', { accept: next.length > 0 ? next : undefined })
}

function setExtras(text: string) {
	const groups = accepted.value.filter((entry) => !entry.startsWith('.'))
	const extras = [...new Set(text.split(/[\s,;]+/).map((part) => part.replace(/^\.+/, '').toLowerCase()).filter((part) => /^[a-z0-9]{1,10}$/.test(part)))].map((part) => '.' + part)
	const next = [...groups, ...extras]
	emit('update', { accept: next.length > 0 ? next : undefined })
}

function setUploadNumber(key: 'maxFiles' | 'maxSizeMb', value: string | number, min: number, max: number) {
	const parsed = Math.round(Number(String(value).trim()))
	emit('update', { [key]: String(value).trim() === '' || Number.isNaN(parsed) ? undefined : Math.min(max, Math.max(min, parsed)) } as Partial<FormField>)
}

/** The picker only shows what you can see in your own Files, and the server checks that you can write there. */
async function pickFolder() {
	let chosen: string | null = null
	const start = '/' + (field.value?.folder ?? '')
	const picker = getFilePickerBuilder(t('webforms', 'Choose a folder for the uploaded files'))
		.setMultiSelect(false)
		.setMimeTypeFilter(['httpd/unix-directory'])
		.allowDirectories(true)
		.startAt(start)
		.setButtonFactory((nodes, path) => [{
			label: nodes.length > 0 ? t('webforms', 'Choose {name}', { name: nodes[0].basename }) : t('webforms', 'Choose this folder'),
			variant: 'primary',
			callback: (picked) => {
				chosen = picked[0]?.path ?? path
			},
		}])
		.build()
	try {
		await picker.pickNodes()
	} catch {
		return
	}
	const folder = (chosen ?? '').replace(/^\/+|\/+$/g, '')
	if (folder !== '') {
		emit('update', { folder })
	}
}

const moves: { move: Move, label: string, icon: string }[] = [
	{ move: 'rowUp', label: t('webforms', 'Move row up'), icon: icons.moveRowUp },
	{ move: 'rowDown', label: t('webforms', 'Move row down'), icon: icons.moveRowDown },
	{ move: 'left', label: t('webforms', 'Move left'), icon: icons.moveLeft },
	{ move: 'right', label: t('webforms', 'Move right'), icon: icons.moveRight },
	{ move: 'joinAbove', label: t('webforms', 'Move into the row above'), icon: icons.joinAbove },
	{ move: 'ownRow', label: t('webforms', 'Put in its own row'), icon: icons.ownRow },
]
</script>

<template>
	<div v-if="field" class="af-props">
		<NcNoteCard v-if="errorCodes.length > 0" type="warning">
			<p v-for="code in errorCodes" :key="code">
				{{ describeError(code) }}
			</p>
		</NcNoteCard>

		<section class="af-props__section">
			<template v-if="hasLabel">
				<NcTextField :model-value="field.label ?? ''"
					:label="t('webforms', 'Label')"
					@update:model-value="(value: string | number) => emit('update', { label: String(value) })" />
				<NcTextField :model-value="field.description ?? ''"
					:label="t('webforms', 'Help text')"
					@update:model-value="(value: string | number) => text('description', value)" />
				<NcTextField v-if="hasPlaceholder"
					:model-value="field.placeholder ?? ''"
					:label="type === 'select' ? t('webforms', 'Prompt') : t('webforms', 'Placeholder')"
					@update:model-value="(value: string | number) => text('placeholder', value)" />
				<DefaultValueEditor v-if="canPrefill" :field-id="fieldId" :field="field" @change="(value) => emit('update', { defaultValue: value })" />
				<NcCheckboxRadioSwitch type="switch"
					:model-value="field.required === true"
					@update:model-value="(value: boolean) => emit('update', { required: value })">
					{{ type === 'checkbox' ? t('webforms', 'Must be ticked') : t('webforms', 'Required') }}
				</NcCheckboxRadioSwitch>
				<div class="af-props__group">
					<span class="af-props__caption">{{ t('webforms', 'Label position') }}</span>
					<SegmentedControl :model-value="labelChoice"
						:group-label="t('webforms', 'Label position')"
						:options="labelOptions"
						@update:model-value="(value) => setLabelPosition(String(value))" />
					<p class="af-props__hint">
						{{ labelHint }}
					</p>
				</div>
			</template>

			<template v-else-if="isHidden">
				<NcTextField :model-value="field.label ?? ''"
					:label="t('webforms', 'Label')"
					:helper-text="t('webforms', 'Names the value in responses, downloads and emails. Respondents never see it.')"
					@update:model-value="(value: string | number) => emit('update', { label: String(value) })" />
				<DefaultValueEditor :field-id="fieldId" :field="field" @change="(value) => emit('update', { defaultValue: value })" />
				<p class="af-props__hint">
					{{ t('webforms', 'Sent when the link does not fill it. Leave empty to send nothing.') }}
				</p>
				<NcTextField :model-value="field.description ?? ''"
					:label="t('webforms', 'Note')"
					:helper-text="t('webforms', 'Only for you, for example what fills this value.')"
					@update:model-value="(value: string | number) => text('description', value)" />
				<NcNoteCard type="info">
					{{ t('webforms', 'A respondent can see and change this value in the link or the page. Do not use it for anything that must not be faked.') }}
				</NcNoteCard>
			</template>

			<template v-else-if="type === 'heading'">
				<NcTextField :model-value="field.text ?? ''"
					:label="t('webforms', 'Heading text')"
					@update:model-value="(value: string | number) => emit('update', { text: String(value) })" />
				<div class="af-props__group">
					<span class="af-props__caption">{{ t('webforms', 'Heading level') }}</span>
					<SegmentedControl :model-value="field.level ?? 2"
						:group-label="t('webforms', 'Heading level')"
						:options="[1, 2, 3, 4, 5, 6].map((level) => ({ value: level, label: t('webforms', 'Heading {level}', { level }), icon: headingIcons[level - 1] }))"
					icon-only
						@update:model-value="(value) => emit('update', { level: Number(value) })" />
					<p class="af-props__hint">
						{{ t('webforms', 'H1 is the largest and H6 the smallest. Keep headings in order for screen readers. The form title is already a level 1 heading, so H2 is usually the right start.') }}
					</p>
				</div>
				<div class="af-props__group">
					<span class="af-props__caption">{{ t('webforms', 'Alignment') }}</span>
					<SegmentedControl :model-value="field.align ?? 'start'"
						:group-label="t('webforms', 'Text alignment')"
						:options="alignOptions"
						icon-only
						@update:model-value="(value) => setAlign(String(value))" />
				</div>
			</template>

			<template v-else-if="type === 'paragraph'">
				<NcTextArea :model-value="field.text ?? ''"
					:label="t('webforms', 'Text')"
					:helper-text="t('webforms', 'Basic markdown works: bold, italic, lists and links.')"
					rows="5"
					@update:model-value="(value: string) => emit('update', { text: value })" />
				<div class="af-props__group">
					<span class="af-props__caption">{{ t('webforms', 'Alignment') }}</span>
					<SegmentedControl :model-value="field.align ?? 'start'"
						:group-label="t('webforms', 'Text alignment')"
						:options="alignOptions"
						icon-only
						@update:model-value="(value) => setAlign(String(value))" />
				</div>
			</template>

			<div v-else class="af-props__group">
				<span class="af-props__caption">{{ t('webforms', 'Line style') }}</span>
				<SegmentedControl :model-value="field.style ?? 'solid'"
					:group-label="t('webforms', 'Line style')"
					:options="lineOptions"
					icon-only
					@update:model-value="(value) => emit('update', { style: value === 'dashed' || value === 'dotted' ? value : undefined })" />
			</div>
		</section>

		<section v-if="type === 'file'" class="af-props__section">
			<h3 class="af-props__heading">
				{{ t('webforms', 'Files') }}
			</h3>
			<NcTextField :model-value="String(field.maxFiles ?? DEFAULT_MAX_FILES)"
				type="number"
				min="1"
				max="10"
				:label="t('webforms', 'Most files (1 to 10)')"
				@update:model-value="(value: string | number) => setUploadNumber('maxFiles', value, 1, 10)" />
			<NcTextField :model-value="String(field.maxSizeMb ?? DEFAULT_MAX_SIZE_MB)"
				type="number"
				min="1"
				max="100"
				:label="t('webforms', 'Largest file, in MB')"
				@update:model-value="(value: string | number) => setUploadNumber('maxSizeMb', value, 1, 100)" />
			<div class="af-props__group">
				<span class="af-props__caption">{{ t('webforms', 'Accepted types') }}</span>
				<NcCheckboxRadioSwitch v-for="group in Object.keys(UPLOAD_GROUPS)"
					:key="group"
					:model-value="accepted.includes(group)"
					:disabled="accepted.length === 1 && accepted[0] === group"
					@update:model-value="(value: boolean) => toggleGroup(group, value)">
					{{ groupLabels[group] }}
				</NcCheckboxRadioSwitch>
				<NcTextField :model-value="extraExtensions"
					:label="t('webforms', 'Other extensions')"
					:placeholder="t('webforms', 'dwg, stl')"
					@update:model-value="(value: string | number) => setExtras(String(value))" />
				<p class="af-props__hint">
					{{ t('webforms', 'Programs, scripts and web pages are never accepted.') }}
				</p>
			</div>
		</section>

		<section v-if="type === 'file'" class="af-props__section">
			<h3 class="af-props__heading">
				{{ t('webforms', 'Who can upload') }}
			</h3>
			<SegmentedControl :model-value="field.audience ?? 'anyone'"
				:group-label="t('webforms', 'Who can upload')"
				:options="[
					{ value: 'anyone', label: t('webforms', 'Anyone'), icon: icons.audienceAnyone },
					{ value: 'login', label: t('webforms', 'Signed-in users'), icon: icons.audienceLogin },
				]"
				@update:model-value="(value) => emit('update', { audience: value === 'login' ? 'login' : undefined })" />
			<p class="af-props__hint">
				{{ uploadStorage === 'files' && (field.audience ?? 'anyone') === 'anyone'
					? t('webforms', 'Letting anyone upload into your files works like a public upload link, so it is only allowed if you may share that folder by link.')
					: t('webforms', 'Uploads are only accepted from people who are signed in to this Nextcloud.') }}
			</p>

			<h3 class="af-props__heading">
				{{ t('webforms', 'Where files are saved') }}
			</h3>
			<SegmentedControl :model-value="uploadStorage"
				:group-label="t('webforms', 'Where files are saved')"
				:options="[
					{ value: 'files', label: t('webforms', 'Your Files'), icon: icons.storageFiles },
					{ value: 'app', label: t('webforms', 'App only'), icon: icons.storageApp },
				]"
				@update:model-value="(value) => emit('update', { storage: value === 'app' ? 'app' : undefined })" />
			<template v-if="uploadStorage === 'files'">
				<NcTextField :model-value="field.folder ?? ''"
					:label="t('webforms', 'Folder')"
					:placeholder="t('webforms', 'Webforms/<form title>')"
					@update:model-value="(value: string | number) => text('folder', value)" />
				<NcButton variant="secondary" @click="pickFolder">
					{{ t('webforms', 'Choose folder') }}
				</NcButton>
				<p class="af-props__hint">
					{{ t('webforms', 'Each response gets its own subfolder here. You can only pick folders you can write to, and this is checked again every time someone submits.') }}
				</p>
			</template>
			<p v-else class="af-props__hint">
				{{ t('webforms', 'Files stay in the app and are downloaded from the Responses view.') }}
			</p>
		</section>

		<section v-if="['text', 'textarea', 'number', 'date', 'time'].includes(type) || hasOptions" class="af-props__section">
			<h3 class="af-props__heading">
				{{ hasOptions ? t('webforms', 'Options') : t('webforms', 'Limits') }}
			</h3>
			<OptionsEditor v-if="hasOptions"
				:options="field.options ?? []"
				@update:options="(options) => emit('update', { options })" />
			<template v-if="type === 'text' || type === 'textarea'">
				<NcTextField :model-value="field.minLength === undefined ? '' : String(field.minLength)"
					type="number"
					:label="t('webforms', 'Minimum length')"
					@update:model-value="(value: string | number) => number('minLength', value)" />
				<NcTextField :model-value="field.maxLength === undefined ? '' : String(field.maxLength)"
					type="number"
					:label="t('webforms', 'Maximum length')"
					@update:model-value="(value: string | number) => number('maxLength', value)" />
			</template>
			<NcTextField v-if="type === 'textarea'"
				:model-value="String(field.rows ?? 5)"
				type="number"
				min="2"
				max="20"
				:label="t('webforms', 'Visible lines (2 to 20)')"
				@update:model-value="(value: string | number) => setRows(value)" />
			<template v-if="type === 'date' || type === 'time'">
				<div class="af-props__group">
					<label class="af-props__caption" for="af-props-min">{{ type === 'date' ? t('webforms', 'Earliest date') : t('webforms', 'Earliest time') }}</label>
					<input id="af-props-min"
						:type="type"
						:value="typeof field.min === 'string' ? field.min : ''"
						:max="typeof field.max === 'string' ? field.max : undefined"
						@change="text('min', ($event.target as HTMLInputElement).value)">
				</div>
				<div class="af-props__group">
					<label class="af-props__caption" for="af-props-max">{{ type === 'date' ? t('webforms', 'Latest date') : t('webforms', 'Latest time') }}</label>
					<input id="af-props-max"
						:type="type"
						:value="typeof field.max === 'string' ? field.max : ''"
						:min="typeof field.min === 'string' ? field.min : undefined"
						@change="text('max', ($event.target as HTMLInputElement).value)">
				</div>
			</template>
			<div v-if="type === 'radio' || type === 'checkboxes'" class="af-props__group">
				<span class="af-props__caption">{{ t('webforms', 'Columns') }}</span>
				<SegmentedControl :model-value="field.columns ?? 1"
					:group-label="t('webforms', 'Columns')"
					:options="[1, 2, 3, 4].map((n) => ({ value: n, label: t('webforms', '{n} columns', { n }), icon: columnIcons[n - 1] }))"
					icon-only
					@update:model-value="(value) => emit('update', { columns: Number(value) > 1 ? Number(value) : undefined })" />
			</div>
			<template v-if="type === 'number'">
				<NcTextField :model-value="field.prefix ?? ''"
					:label="t('webforms', 'Text before the number')"
					:placeholder="t('webforms', '$')"
					@update:model-value="(value: string | number) => text('prefix', String(value).slice(0, 16))" />
				<NcTextField :model-value="field.suffix ?? ''"
					:label="t('webforms', 'Text after the number')"
					:placeholder="t('webforms', 'kg')"
					@update:model-value="(value: string | number) => text('suffix', String(value).slice(0, 16))" />
				<NcTextField :model-value="field.min === undefined ? '' : String(field.min)"
					type="number"
					:label="t('webforms', 'Minimum')"
					@update:model-value="(value: string | number) => number('min', value)" />
				<NcTextField :model-value="field.max === undefined ? '' : String(field.max)"
					type="number"
					:label="t('webforms', 'Maximum')"
					@update:model-value="(value: string | number) => number('max', value)" />
				<NcTextField :model-value="field.step === undefined ? '' : String(field.step)"
					type="number"
					:label="t('webforms', 'Step')"
					@update:model-value="(value: string | number) => number('step', value)" />
			</template>
			<template v-if="type === 'checkboxes'">
				<NcTextField :model-value="field.minSelected === undefined ? '' : String(field.minSelected)"
					type="number"
					:label="t('webforms', 'Choose at least')"
					@update:model-value="(value: string | number) => number('minSelected', value)" />
				<NcTextField :model-value="field.maxSelected === undefined ? '' : String(field.maxSelected)"
					type="number"
					:label="t('webforms', 'Choose at most')"
					@update:model-value="(value: string | number) => number('maxSelected', value)" />
			</template>
		</section>

		<section v-if="canPrefill" class="af-props__section">
			<h3 class="af-props__heading">
				{{ t('webforms', 'Fill from the link') }}
			</h3>
			<p v-if="prefillOff" class="af-props__hint">
				{{ t('webforms', 'Switched off for this form under Settings, General.') }}
			</p>
			<template v-else>
				<div class="af-props__link">
					<code class="af-props__param">{{ linkParameter }}{{ t('webforms', 'value') }}</code>
					<NcButton variant="tertiary" @click="copyParameter">
						{{ copied ? t('webforms', 'Copied') : t('webforms', 'Copy') }}
					</NcButton>
				</div>
				<p class="af-props__hint">
					{{ t('webforms', 'Add this to the form link to fill the field when it opens. It uses the field ID, which you can change under Advanced.') }}
				</p>
				<p v-if="type === 'checkboxes'" class="af-props__hint">
					{{ t('webforms', 'Choices are matched by their label or value. Separate several with commas.') }}
				</p>
				<p v-else-if="type === 'checkbox'" class="af-props__hint">
					{{ t('webforms', 'Use yes or no.') }}
				</p>
				<p v-else-if="hasOptions" class="af-props__hint">
					{{ t('webforms', 'The choice is matched by its label or value.') }}
				</p>
			</template>
		</section>

		<section class="af-props__section">
			<h3 class="af-props__heading">
				{{ t('webforms', 'Layout') }}
			</h3>
			<div class="af-props__group">
				<span class="af-props__caption">{{ t('webforms', 'Width') }}</span>
				<SegmentedControl :model-value="currentSpan"
					:group-label="t('webforms', 'Field width')"
					:options="spanOptions"
					@update:model-value="(value) => emit('span', Number(value))" />
			</div>
			<div class="af-props__moves">
				<NcButton v-for="item in moves"
					:key="item.move"
					variant="secondary"
					:aria-label="item.label"
					:title="item.label"
					:disabled="!canMove(definition, fieldId, item.move)"
					@click="emit('move', item.move)">
					<template #icon>
						<Icon :path="item.icon" />
					</template>
				</NcButton>
			</div>
			<div v-if="definition.pages.length > 1" class="af-props__group">
				<label class="af-props__caption" for="af-props-page">{{ t('webforms', 'Page') }}</label>
				<select id="af-props-page"
					:value="locateField(definition, fieldId)?.pageIndex ?? 0"
					@change="emit('toPage', Number(($event.target as HTMLSelectElement).value))">
					<option v-for="(page, index) in definition.pages" :key="page.id" :value="index">
						{{ page.title || t('webforms', 'Page {n}', { n: index + 1 }) }}
					</option>
				</select>
			</div>
			<AddFieldMenu v-if="roomBeside || canShareRow"
				:label="t('webforms', 'Add a field beside this one')"
				variant="tertiary"
				@pick="(newType) => emit('addBeside', newType)" />
		</section>

		<section class="af-props__section">
			<h3 class="af-props__heading">
				{{ t('webforms', 'Advanced') }}
			</h3>
			<NcTextField :model-value="idDraft"
				:label="t('webforms', 'Field ID')"
				:error="idMessage !== null"
				:helper-text="idMessage ?? t('webforms', 'Sent with webhooks and used in exports. It follows the label until you change it yourself. Rules, actions and layout follow if you change it.')"
				@update:model-value="(value: string | number) => { idDraft = String(value) }"
				@keydown.enter.prevent="applyId" />
			<NcNoteCard v-if="published && idProblem === null" type="info">
				{{ t('webforms', 'This form is published. After you publish again, webhooks, exports and links that fill this field use the new ID. Responses already received keep the old one.') }}
			</NcNoteCard>
			<NcButton variant="secondary" :disabled="idProblem !== null" @click="applyId">
				{{ t('webforms', 'Change ID') }}
			</NcButton>
		</section>

		<section class="af-props__section af-props__actions">
			<NcButton variant="secondary"
				:disabled="Object.keys(definition.fields).length >= MAX_FIELDS"
				:title="Object.keys(definition.fields).length >= MAX_FIELDS ? t('webforms', 'This form has the most fields it can have.') : undefined"
				@click="emit('duplicate')">
				<template #icon>
					<Icon :path="icons.copy" />
				</template>
				{{ t('webforms', 'Duplicate field') }}
			</NcButton>
			<NcButton variant="error" @click="emit('remove')">
				<template #icon>
					<Icon :path="icons.delete" />
				</template>
				{{ t('webforms', 'Delete field') }}
			</NcButton>
		</section>
	</div>
</template>

<style scoped>
.af-props {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 6);
}

.af-props__section {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.af-props__heading {
	margin: 0;
	font-size: 14px;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.af-props__link {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.af-props__param {
	overflow-wrap: anywhere;
	padding: 2px 6px;
	border-radius: var(--border-radius-small);
	background: var(--color-background-dark);
}

.af-props__group {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.af-props__caption {
	font-weight: bold;
}

.af-props__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.af-props__moves {
	display: grid;
	grid-template-columns: repeat(6, minmax(0, 1fr));
	gap: var(--default-grid-baseline);
}

.af-props__moves :deep(.button-vue) {
	width: 100%;
}
</style>
