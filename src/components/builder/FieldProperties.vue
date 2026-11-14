<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { DraftDefinition, FormField } from '../../types.ts'
import type { Move } from '../../builder/definition.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref } from 'vue'
import { allowedSpans, canMove, locateField, SPANS } from '../../builder/definition.ts'
import { describeError } from '../../builder/errors.ts'
import { fieldIdProblem } from '../../builder/fieldId.ts'
import { renderedLabelPosition, requestedLabelPosition, supportsInline } from '../../layout/labelPosition.ts'
import AddFieldMenu from './AddFieldMenu.vue'
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
}>()

const field = computed(() => props.definition.fields[props.fieldId])
const type = computed(() => field.value?.type ?? '')

const isDisplay = computed(() => ['heading', 'paragraph', 'divider'].includes(type.value))
const hasPlaceholder = computed(() => ['text', 'textarea', 'email', 'number', 'phone', 'select'].includes(type.value))
const hasOptions = computed(() => ['select', 'radio', 'checkboxes'].includes(type.value))
const hasLabel = computed(() => !isDisplay.value)

// The segment shown: what this field sets itself, or "Form" when it follows the form-wide default.
const labelChoice = computed(() => {
	if (field.value?.labelPosition) {
		return field.value.labelPosition
	}
	return field.value?.hideLabel === true ? 'hidden' : 'default'
})
const labelOptions = computed(() => [
	{ value: 'default', label: t('advancedforms', 'Form') },
	{ value: 'above', label: t('advancedforms', 'Above') },
	{
		value: 'inline',
		label: t('advancedforms', 'Beside'),
		disabled: !supportsInline(type.value),
		title: supportsInline(type.value) ? undefined : t('advancedforms', 'Not available for this type of field'),
	},
	{ value: 'hidden', label: t('advancedforms', 'Hidden') },
])

function positionName(position: string): string {
	switch (position) {
		case 'inline': return t('advancedforms', 'beside the field')
		case 'hidden': return t('advancedforms', 'hidden')
		default: return t('advancedforms', 'above the field')
	}
}

const labelHint = computed(() => {
	const rendered = renderedLabelPosition(field.value, props.definition.settings)
	if (labelChoice.value === 'default') {
		const formWide = requestedLabelPosition({ type: type.value }, props.definition.settings)
		if (formWide === 'inline' && rendered !== 'inline') {
			return t('advancedforms', 'Following the form setting. The label stays above this kind of field.')
		}
		return t('advancedforms', 'Following the form setting: {position}.', { position: positionName(rendered) })
	}
	if (labelChoice.value === 'hidden') {
		return t('advancedforms', 'Not shown, but still read by screen readers.')
	}
	if (labelChoice.value === 'inline') {
		return t('advancedforms', 'Moves above the field when there is not enough room.')
	}
	return t('advancedforms', 'Always above the field.')
})

/** Setting a position replaces the older hideLabel flag, so the two never disagree. */
const alignOptions = computed(() => [
	{ value: 'start', label: t('advancedforms', 'Left') },
	{ value: 'center', label: t('advancedforms', 'Center') },
	{ value: 'end', label: t('advancedforms', 'Right') },
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
const idProblem = computed(() => fieldIdProblem(props.definition, props.fieldId, idDraft.value, props.retiredIds ?? []))
const idMessage = computed(() => {
	switch (idProblem.value) {
		case 'pattern': return t('advancedforms', 'Use 3 to 32 lowercase letters, numbers or underscores, starting with a letter.')
		case 'taken': return t('advancedforms', 'Another field already uses this ID.')
		case 'retired': return t('advancedforms', 'A published field used this ID before it was deleted, so it cannot be used again.')
		default: return null
	}
})

function applyId(): void {
	if (idProblem.value === null) {
		emit('rename', idDraft.value)
	}
}

const spanLabels: Record<number, string> = { 3: '¼', 4: '⅓', 6: '½', 8: '⅔', 9: '¾', 12: t('advancedforms', 'Full') }
const allowed = computed(() => allowedSpans(props.definition, props.fieldId))
const spanOptions = computed(() => SPANS.map((span) => ({
	value: span as number,
	label: spanLabels[span],
	disabled: !allowed.value.includes(span),
	title: allowed.value.includes(span) ? undefined : t('advancedforms', 'Not enough room in this row'),
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

const moves: { move: Move, label: string, icon: string }[] = [
	{ move: 'rowUp', label: t('advancedforms', 'Move row up'), icon: icons.moveRowUp },
	{ move: 'rowDown', label: t('advancedforms', 'Move row down'), icon: icons.moveRowDown },
	{ move: 'left', label: t('advancedforms', 'Move left'), icon: icons.moveLeft },
	{ move: 'right', label: t('advancedforms', 'Move right'), icon: icons.moveRight },
	{ move: 'joinAbove', label: t('advancedforms', 'Move into the row above'), icon: icons.joinAbove },
	{ move: 'ownRow', label: t('advancedforms', 'Put in its own row'), icon: icons.ownRow },
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
					:label="t('advancedforms', 'Label')"
					@update:model-value="(value: string | number) => emit('update', { label: String(value) })" />
				<NcTextField :model-value="field.description ?? ''"
					:label="t('advancedforms', 'Help text')"
					@update:model-value="(value: string | number) => text('description', value)" />
				<NcTextField v-if="hasPlaceholder"
					:model-value="field.placeholder ?? ''"
					:label="type === 'select' ? t('advancedforms', 'Prompt') : t('advancedforms', 'Placeholder')"
					@update:model-value="(value: string | number) => text('placeholder', value)" />
				<NcCheckboxRadioSwitch type="switch"
					:model-value="field.required === true"
					@update:model-value="(value: boolean) => emit('update', { required: value })">
					{{ type === 'checkbox' ? t('advancedforms', 'Must be ticked') : t('advancedforms', 'Required') }}
				</NcCheckboxRadioSwitch>
				<div class="af-props__group">
					<span class="af-props__caption">{{ t('advancedforms', 'Label position') }}</span>
					<SegmentedControl :model-value="labelChoice"
						:group-label="t('advancedforms', 'Label position')"
						:options="labelOptions"
						@update:model-value="(value) => setLabelPosition(String(value))" />
					<p class="af-props__hint">
						{{ labelHint }}
					</p>
				</div>
			</template>

			<template v-else-if="type === 'heading'">
				<NcTextField :model-value="field.text ?? ''"
					:label="t('advancedforms', 'Heading text')"
					@update:model-value="(value: string | number) => emit('update', { text: String(value) })" />
				<div class="af-props__group">
					<span class="af-props__caption">{{ t('advancedforms', 'Size') }}</span>
					<SegmentedControl :model-value="field.level ?? 2"
						:group-label="t('advancedforms', 'Heading size')"
						:options="[
							{ value: 2, label: t('advancedforms', 'Large') },
							{ value: 3, label: t('advancedforms', 'Medium') },
							{ value: 4, label: t('advancedforms', 'Small') },
						]"
						@update:model-value="(value) => emit('update', { level: Number(value) })" />
				</div>
				<div class="af-props__group">
					<span class="af-props__caption">{{ t('advancedforms', 'Alignment') }}</span>
					<SegmentedControl :model-value="field.align ?? 'start'"
						:group-label="t('advancedforms', 'Text alignment')"
						:options="alignOptions"
						@update:model-value="(value) => setAlign(String(value))" />
				</div>
			</template>

			<template v-else-if="type === 'paragraph'">
				<NcTextArea :model-value="field.text ?? ''"
					:label="t('advancedforms', 'Text')"
					:helper-text="t('advancedforms', 'Basic markdown works: bold, italic, lists and links.')"
					rows="5"
					@update:model-value="(value: string) => emit('update', { text: value })" />
				<div class="af-props__group">
					<span class="af-props__caption">{{ t('advancedforms', 'Alignment') }}</span>
					<SegmentedControl :model-value="field.align ?? 'start'"
						:group-label="t('advancedforms', 'Text alignment')"
						:options="alignOptions"
						@update:model-value="(value) => setAlign(String(value))" />
				</div>
			</template>

			<p v-else class="af-props__hint">
				{{ t('advancedforms', 'A divider has no settings.') }}
			</p>
		</section>

		<section v-if="type === 'text' || type === 'textarea' || type === 'number' || type === 'checkboxes' || hasOptions" class="af-props__section">
			<h3 class="af-props__heading">
				{{ hasOptions ? t('advancedforms', 'Options') : t('advancedforms', 'Limits') }}
			</h3>
			<OptionsEditor v-if="hasOptions"
				:options="field.options ?? []"
				@update:options="(options) => emit('update', { options })" />
			<NcTextField v-if="type === 'text' || type === 'textarea'"
				:model-value="field.maxLength === undefined ? '' : String(field.maxLength)"
				type="number"
				:label="t('advancedforms', 'Maximum length')"
				@update:model-value="(value: string | number) => number('maxLength', value)" />
			<template v-if="type === 'number'">
				<NcTextField :model-value="field.min === undefined ? '' : String(field.min)"
					type="number"
					:label="t('advancedforms', 'Minimum')"
					@update:model-value="(value: string | number) => number('min', value)" />
				<NcTextField :model-value="field.max === undefined ? '' : String(field.max)"
					type="number"
					:label="t('advancedforms', 'Maximum')"
					@update:model-value="(value: string | number) => number('max', value)" />
				<NcTextField :model-value="field.step === undefined ? '' : String(field.step)"
					type="number"
					:label="t('advancedforms', 'Step')"
					@update:model-value="(value: string | number) => number('step', value)" />
			</template>
			<template v-if="type === 'checkboxes'">
				<NcTextField :model-value="field.minSelected === undefined ? '' : String(field.minSelected)"
					type="number"
					:label="t('advancedforms', 'Choose at least')"
					@update:model-value="(value: string | number) => number('minSelected', value)" />
				<NcTextField :model-value="field.maxSelected === undefined ? '' : String(field.maxSelected)"
					type="number"
					:label="t('advancedforms', 'Choose at most')"
					@update:model-value="(value: string | number) => number('maxSelected', value)" />
			</template>
		</section>

		<section class="af-props__section">
			<h3 class="af-props__heading">
				{{ t('advancedforms', 'Layout') }}
			</h3>
			<div class="af-props__group">
				<span class="af-props__caption">{{ t('advancedforms', 'Width') }}</span>
				<SegmentedControl :model-value="currentSpan"
					:group-label="t('advancedforms', 'Field width')"
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
				<label class="af-props__caption" for="af-props-page">{{ t('advancedforms', 'Page') }}</label>
				<select id="af-props-page"
					:value="locateField(definition, fieldId)?.pageIndex ?? 0"
					@change="emit('toPage', Number(($event.target as HTMLSelectElement).value))">
					<option v-for="(page, index) in definition.pages" :key="page.id" :value="index">
						{{ page.title || t('advancedforms', 'Page {n}', { n: index + 1 }) }}
					</option>
				</select>
			</div>
			<AddFieldMenu v-if="roomBeside || canShareRow"
				:label="t('advancedforms', 'Add a field beside this one')"
				variant="tertiary"
				@pick="(newType) => emit('addBeside', newType)" />
		</section>

		<section class="af-props__section">
			<h3 class="af-props__heading">
				{{ t('advancedforms', 'Advanced') }}
			</h3>
			<NcTextField :model-value="idDraft"
				:label="t('advancedforms', 'Field ID')"
				:error="idMessage !== null"
				:helper-text="idMessage ?? t('advancedforms', 'Sent with webhooks and used in exports. Rules, actions and layout follow if you change it.')"
				@update:model-value="(value: string | number) => { idDraft = String(value) }"
				@keydown.enter.prevent="applyId" />
			<NcNoteCard v-if="published && idProblem === null" type="info">
				{{ t('advancedforms', 'This form is published. After you publish again, webhooks and exports use the new ID. Responses already received keep the old one.') }}
			</NcNoteCard>
			<NcButton variant="secondary" :disabled="idProblem !== null" @click="applyId">
				{{ t('advancedforms', 'Change ID') }}
			</NcButton>
		</section>

		<section class="af-props__section">
			<NcButton variant="error" @click="emit('remove')">
				<template #icon>
					<Icon :path="icons.delete" />
				</template>
				{{ t('advancedforms', 'Delete field') }}
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
