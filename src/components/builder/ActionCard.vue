<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { MailPolicy } from '../../api/settings.ts'
import type { DraftDefinition, EmailAction, EmailAddressSource, FilesAction, FormAction, MessageAction, RedirectAction, TablesAction, WebhookAction } from '../../types.ts'

import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { fieldTypeIcon, icons } from '../../icons.ts'
import Icon from '../common/Icon.vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref } from 'vue'
import { defaultWhen, emailFields, readablePlaceholders, setWhen, unknownPlaceholders, usesResponseSlot, withAllFields, withPlaceholder } from '../../builder/actions.ts'
import { describeError } from '../../builder/errors.ts'
import { actionLabel } from '../../builder/labels.ts'
import { sourceFields } from '../../builder/rules.ts'
import ConditionGroup from './ConditionGroup.vue'
import PlaceholderMenu from './PlaceholderMenu.vue'
import FilesActionBody from './FilesActionBody.vue'
import TablesActionBody from './TablesActionBody.vue'

const ARROW_UP: string = icons.arrowUp
const ARROW_DOWN: string = icons.arrowDown
const TRASH: string = icons.delete

const props = defineProps<{
	action: FormAction
	index: number
	count: number
	definition: DraftDefinition
	expanded: boolean
	errorCodes: string[]
	/** Show problems even on a card nobody has touched, e.g. after a failed publish. */
	showErrors?: boolean
	/** A message or redirect that an earlier one always pre-empts. */
	unreachable: boolean
	/** An administrator has switched this kind of action off. */
	switchedOff?: boolean
	/** An editor, not the owner: actions that reach into the owner's Files or Tables are read only (docs/SCHEMA.md section 22.5). */
	ownerOnly?: boolean
	/** The administrator's settings for mail. Left out, everything is allowed and there is no default. */
	mail?: MailPolicy
	/** A freshly generated secret to show once, for this action. */
	revealedSecret: string | null
	secretBusy: boolean
	secretFailed: boolean
}>()

/** The owner's Files and Tables are the owner's to point at (docs/SCHEMA.md section 22.5). */
const locked = computed(() => props.ownerOnly === true && (props.action.type === 'files' || props.action.type === 'tables'))

const emit = defineEmits<{
	update: [action: FormAction]
	remove: []
	move: [by: -1 | 1]
	toggle: []
	generateSecret: []
	dismissSecret: []
}>()

const sources = computed(() => sourceFields(props.definition))
const emails = computed(() => emailFields(props.definition))
const noForbidden: ReadonlySet<string> = new Set()

const title = computed(() => actionLabel(props.action.type))
function recipientSummary(to: EmailAction['to']): string {
	if (typeof to === 'string') {
		return to
	}
	if ('owner' in to) {
		return t('webforms', 'you')
	}
	if ('respondent' in to) {
		return t('webforms', 'the signed-in respondent')
	}
	return props.definition.fields[to.field]?.label || t('webforms', 'the respondent')
}

function tablesSummary(action: TablesAction): string {
	const count = Object.keys(action.map).length
	if (action.tableId < 1) {
		return t('webforms', 'Pick a table')
	}
	return n('webforms', '{count} column filled', '{count} columns filled', count, { count })
}

const summary = computed(() => {
	const action = props.action
	switch (action.type) {
		case 'message': return readablePlaceholders(action.text, props.definition)
		case 'redirect': return readablePlaceholders(action.url, props.definition)
		case 'webhook': return action.url
		case 'tables': return tablesSummary(action)
		case 'files': return action.path
		default: {
			const to = recipientSummary(action.to)
			return t('webforms', 'To {to}: {subject}', { to: to || '…', subject: readablePlaceholders(action.subject, props.definition) })
		}
	}
})

const conditional = computed(() => props.action.when !== undefined)
const whenGroup = computed(() => {
	const when = props.action.when
	return when && ('all' in when || 'any' in when) ? when : null
})
const canAddWhen = computed(() => defaultWhen(props.definition) !== null)

/** Placeholders in the text that no longer match a field. */
const brokenPlaceholders = computed(() => {
	const texts: string[] = []
	const action = props.action
	if (action.type === 'message') {
		texts.push(action.text)
	} else if (action.type === 'redirect') {
		texts.push(action.url)
	} else if (action.type === 'email') {
		texts.push(action.subject)
	}
	const broken = texts.flatMap((text) => unknownPlaceholders(text, props.definition))
	if (action.type === 'email') {
		// Only the body may ask for the list of all answers.
		broken.push(...unknownPlaceholders(action.body, props.definition, true))
	}
	return [...new Set(broken)]
})

// The action types are a union, so narrow once per type for the template.
const message = computed(() => props.action as MessageAction)
const redirect = computed(() => props.action as RedirectAction)
const email = computed(() => props.action as EmailAction)
const webhook = computed(() => props.action as WebhookAction)
const tablesAction = computed(() => props.action as TablesAction)
const filesAction = computed(() => props.action as FilesAction)

function patch(changes: Record<string, unknown>): void {
	emit('update', { ...props.action, ...changes } as FormAction)
}

function setWhenEnabled(enabled: boolean): void {
	const next = enabled ? defaultWhen(props.definition) : null
	emit('update', setWhen(props.action, next))
}

const MAIL_ICON: string = icons.mail
const EMAIL_FIELD_ICON: string = fieldTypeIcon('email')
const ACCOUNT_ICON: string = icons.account

type RecipientKind = 'field' | 'address' | 'owner' | 'respondent'

interface RecipientOption {
	id: string
	label: string
	kind: RecipientKind
}

const OWNER_OPTION: RecipientOption = { id: '@owner', label: t('webforms', 'Me (the form owner)'), kind: 'owner' }
const RESPONDENT_OPTION: RecipientOption = { id: '@respondent', label: t('webforms', 'The signed-in respondent'), kind: 'respondent' }

// The two account entries come first; the form's email fields follow. Typing adds a fixed address.
const recipientOptions = computed<RecipientOption[]>(() => [
	OWNER_OPTION,
	RESPONDENT_OPTION,
	...emails.value.map(({ id, label }): RecipientOption => ({ id, label: label || id, kind: 'field' })),
])

/** The entry in the box for an address source, or null for none. */
function optionFor(source: EmailAddressSource | undefined): RecipientOption | null {
	if (source === undefined) {
		return null
	}
	if (typeof source === 'string') {
		return source === '' ? null : { id: source, label: source, kind: 'address' }
	}
	if ('owner' in source) {
		return OWNER_OPTION
	}
	if ('respondent' in source) {
		return RESPONDENT_OPTION
	}
	return recipientOptions.value.find((option) => option.kind === 'field' && option.id === source.field) ?? { id: source.field, label: source.field, kind: 'field' }
}

/** The address source an entry in the box stands for. */
function sourceFor(option: RecipientOption): EmailAddressSource {
	switch (option.kind) {
		case 'owner': return { owner: true }
		case 'respondent': return { respondent: true }
		case 'field': return { field: option.id }
		default: return option.id.trim()
	}
}

/** One box for every kind of recipient. */
const recipient = computed<RecipientOption | null>(() => optionFor(email.value.to))

const recipientKind = computed<RecipientKind>(() => recipient.value?.kind ?? 'address')

function setRecipient(option: RecipientOption | null): void {
	patch({ to: option === null ? '' : sourceFor(option) })
}

/** Whether this action's own reply-to and sender name count, or the administrator's are used. */
const mailLocked = computed(() => props.mail?.allowOverride === false)

/** When locked, only "the address the respondent typed" is still the form owner's to pick. */
const replyToOptions = computed(() => (mailLocked.value ? recipientOptions.value.filter((option) => option.kind === 'field') : recipientOptions.value))

/**
 * Where replies go when this action sets no reply-to: the real address, shown in the empty box. The
 * words are only for when the server did not say.
 */
const defaultReplyTo = computed(() => props.mail?.defaultReplyTo || t('webforms', 'Server sender address'))

/** The name mail is sent under when this action sets none. */
const defaultFromName = computed(() => props.mail?.defaultFromName || t('webforms', 'Server default'))

function setFromName(value: string): void {
	if (value.trim() === '') {
		const { fromName: _removed, ...rest } = email.value
		emit('update', rest as FormAction)
	} else {
		patch({ fromName: value })
	}
}

/** The same box for where replies go. It is optional, so clearing it removes the setting. */
const replyTo = computed<RecipientOption | null>(() => optionFor(email.value.replyTo))

function setReplyTo(option: RecipientOption | null): void {
	const source = option === null ? '' : sourceFor(option)
	if (source === '') {
		const { replyTo: _removed, ...rest } = email.value
		emit('update', rest as FormAction)
	} else {
		patch({ replyTo: source })
	}
}

function createRecipient(text: string): RecipientOption {
	return { id: text, label: text, kind: 'address' }
}

function recipientTag(kind: RecipientKind): string {
	switch (kind) {
		case 'owner': return t('webforms', 'Your account email')
		case 'respondent': return t('webforms', 'Their account email')
		case 'field': return t('webforms', 'Form field · the answer given')
		default: return t('webforms', 'Use this address')
	}
}

function recipientIcon(kind: RecipientKind): string {
	switch (kind) {
		case 'owner':
		case 'respondent': return ACCOUNT_ICON
		case 'field': return EMAIL_FIELD_ICON
		default: return MAIL_ICON
	}
}

// A freshly added action is incomplete by nature; warn only once the user has been in it (or tried to publish).
const touched = ref(false)

/** Moving through the card's header or buttons is not editing; leaving a text box is. */
function onFocusOut(event: FocusEvent): void {
	const target = event.target as HTMLElement | null
	if (target && ['INPUT', 'TEXTAREA'].includes(target.tagName)) {
		touched.value = true
	}
}
const visibleErrors = computed(() => (props.showErrors || touched.value ? props.errorCodes : []))
</script>

<template>
	<article class="af-action"
		:class="{ 'af-action--error': visibleErrors.length > 0, 'af-action--open': expanded }"
		@focusout="onFocusOut">
		<button type="button"
			class="af-action__head"
			:aria-expanded="expanded"
			@click="emit('toggle')">
			<span class="af-action__title">{{ title }}</span>
			<span v-if="usesResponseSlot(action)" class="af-action__chip">{{ t('webforms', 'Shown to respondent') }}</span>
			<span v-if="conditional" class="af-action__chip">{{ t('webforms', 'Conditional') }}</span>
			<span v-if="unreachable" class="af-action__chip af-action__chip--warn">{{ t('webforms', 'Never used') }}</span>
			<span class="af-action__summary">{{ summary }}</span>
			<span class="af-action__chevron" :class="{ 'af-action__chevron--open': expanded }" aria-hidden="true" />
		</button>

		<div v-if="expanded" class="af-action__body" :inert="locked || undefined">
			<NcNoteCard v-if="locked" type="info">
				{{ t('webforms', 'Only the owner of this form can change this action, because it uses the owner\'s Files or Tables.') }}
			</NcNoteCard>
			<NcNoteCard v-if="visibleErrors.length > 0" type="warning">
				<p v-for="code in visibleErrors" :key="code">
					{{ describeError(code) }}
				</p>
			</NcNoteCard>
			<NcNoteCard v-if="switchedOff" type="warning">
				{{ t('webforms', 'This kind of action is switched off on this server, so it will not run and the form cannot be published with it. Remove it.') }}
			</NcNoteCard>
			<NcNoteCard v-if="unreachable" type="info">
				{{ t('webforms', 'An earlier message or redirect always applies, so this one is never shown. Give the earlier one a condition, or move this one up.') }}
			</NcNoteCard>
			<NcNoteCard v-if="brokenPlaceholders.length > 0" type="warning">
				{{ t('webforms', 'Uses answers that no longer exist: {names}', { names: brokenPlaceholders.map((name) => '{' + name + '}').join(', ') }) }}
			</NcNoteCard>

			<!-- Message -->
			<template v-if="action.type === 'message'">
				<NcTextArea :model-value="message.text"
					:label="t('webforms', 'Message')"
					rows="3"
					@update:model-value="(value: string) => patch({ text: value })" />
				<PlaceholderMenu :definition="definition" @pick="(id) => patch({ text: withPlaceholder(message.text, id) })" />
			</template>

			<!-- Redirect -->
			<template v-else-if="action.type === 'redirect'">
				<NcTextField :model-value="redirect.url"
					:label="t('webforms', 'Address')"
					:helper-text="t('webforms', 'Must start with https://. Answers can be added after the address, for example ?name=…')"
					@update:model-value="(value: string | number) => patch({ url: String(value) })" />
				<PlaceholderMenu :definition="definition" @pick="(id) => patch({ url: withPlaceholder(redirect.url, id) })" />
			</template>

			<!-- Email -->
			<template v-else-if="action.type === 'email'">
				<NcSelect :model-value="recipient"
					:input-label="t('webforms', 'Send to')"
					:placeholder="t('webforms', 'Pick an email field or type an address')"
					:options="recipientOptions"
					taggable
					:create-option="createRecipient"
					label="label"
					@update:model-value="setRecipient">
					<template #option="option">
						<span class="af-recipient">
							<Icon :path="recipientIcon(option.kind)" :size="18" />
							<span class="af-recipient__label">{{ option.label }}</span>
							<span class="af-recipient__kind">{{ recipientTag(option.kind) }}</span>
						</span>
					</template>
					<template #selected-option="option">
						<span class="af-recipient">
							<span class="af-recipient__label">{{ option.label }}</span>
							<span v-if="option.kind !== 'address'" class="af-recipient__kind">{{ recipientTag(option.kind) }}</span>
						</span>
					</template>
				</NcSelect>
				<p class="af-action__hint">
					<template v-if="recipientKind === 'field'">
						{{ t('webforms', 'Sent to the address the respondent typed. To limit abuse, at most 30 such emails are sent per hour for this form.') }}
					</template>
					<template v-else-if="recipientKind === 'owner'">
						{{ t('webforms', 'Sent to the email address on your Nextcloud account. If you change it, forms follow automatically.') }}
					</template>
					<template v-else-if="recipientKind === 'respondent'">
						{{ t('webforms', 'Sent to the Nextcloud account the respondent is signed in with. Use it on forms that require login; otherwise anonymous responses are skipped.') }}
					</template>
					<template v-else>
						{{ t('webforms', 'Pick who receives it, or type an address and press Enter.') }}
					</template>
				</p>
				<NcSelect :model-value="replyTo"
					class="af-action__defaulted"
					:input-label="t('webforms', 'Reply to (optional)')"
					:placeholder="defaultReplyTo"
					:options="replyToOptions"
					:taggable="!mailLocked"
					:create-option="createRecipient"
					label="label"
					@update:model-value="setReplyTo">
					<template #option="option">
						<span class="af-recipient">
							<Icon :path="recipientIcon(option.kind)" :size="18" />
							<span class="af-recipient__label">{{ option.label }}</span>
							<span class="af-recipient__kind">{{ recipientTag(option.kind) }}</span>
						</span>
					</template>
					<template #selected-option="option">
						<span class="af-recipient">
							<span class="af-recipient__label">{{ option.label }}</span>
							<span v-if="option.kind !== 'address'" class="af-recipient__kind">{{ recipientTag(option.kind) }}</span>
						</span>
					</template>
				</NcSelect>
				<p v-if="mailLocked" class="af-action__hint">
					{{ t('webforms', 'The administrator only lets forms reply to an email field.') }}
				</p>
				<NcTextField v-if="!mailLocked || email.fromName !== undefined"
					class="af-action__defaulted"
					:model-value="email.fromName ?? ''"
					:label="t('webforms', 'Sender name (optional)')"
					:placeholder="defaultFromName"
					:disabled="mailLocked"
					maxlength="100"
					:helper-text="mailLocked ? t('webforms', 'The administrator sets the sender name. Clear this to publish.') : ''"
					@update:model-value="(value: string | number) => setFromName(String(value))" />
				<NcButton v-if="mailLocked && email.fromName !== undefined" variant="tertiary" @click="setFromName('')">
					{{ t('webforms', 'Remove sender name') }}
				</NcButton>
				<NcTextField :model-value="email.subject"
					:label="t('webforms', 'Subject')"
					@update:model-value="(value: string | number) => patch({ subject: String(value) })" />
				<NcTextArea :model-value="email.body"
					:label="t('webforms', 'Body')"
					rows="6"
					@update:model-value="(value: string) => patch({ body: value })" />
				<div class="af-action__inserts">
					<PlaceholderMenu :definition="definition" @pick="(id) => patch({ body: withPlaceholder(email.body, id) })" />
					<NcButton variant="secondary"
						:disabled="email.body.includes('{all_fields}')"
						@click="patch({ body: withAllFields(email.body) })">
						{{ t('webforms', 'Add all answers') }}
					</NcButton>
					<span class="af-action__hint">{{ t('webforms', 'Adds an answer to the end of the body. "All answers" adds {placeholder}, which becomes a list of every question the person answered, with its label.', { placeholder: '{all_fields}' }) }}</span>
				</div>
			</template>

			<!-- Tables -->
			<TablesActionBody v-else-if="action.type === 'tables'"
				:inert="locked || undefined"
				:action="tablesAction"
				:definition="definition"
				@patch="patch" />

			<!-- CSV file in Files -->
			<FilesActionBody v-else-if="action.type === 'files'"
				:inert="locked || undefined"
				:action="filesAction"
				@patch="patch" />

			<!-- Webhook -->
			<template v-else>
				<NcTextField :model-value="webhook.url"
					:label="t('webforms', 'Webhook address')"
					:helper-text="t('webforms', 'Must start with https://. Nextcloud blocks addresses on private networks unless an administrator allows them.')"
					@update:model-value="(value: string | number) => patch({ url: String(value) })" />

				<div class="af-action__group">
					<span class="af-action__caption">{{ t('webforms', 'Signing secret') }}</span>
					<p class="af-action__hint">
						{{ t('webforms', 'Each request is signed so the receiver can check it came from this form. The signature is in the X-Webforms-Signature header.') }}
					</p>
					<NcNoteCard v-if="revealedSecret" type="success">
						<p><strong>{{ t('webforms', 'Copy this secret now. It will not be shown again.') }}</strong></p>
						<code class="af-action__secret">{{ revealedSecret }}</code>
						<NcButton variant="tertiary" @click="emit('dismissSecret')">
							{{ t('webforms', 'I have copied it') }}
						</NcButton>
					</NcNoteCard>
					<NcNoteCard v-if="secretFailed" type="error">
						{{ t('webforms', 'Could not create a secret. Try again.') }}
					</NcNoteCard>
					<div class="af-action__row">
						<span v-if="webhook.secretRef && !revealedSecret" class="af-action__ok">{{ t('webforms', 'A secret is set') }}</span>
						<NcButton variant="secondary" :disabled="secretBusy" @click="emit('generateSecret')">
							{{ webhook.secretRef ? t('webforms', 'Generate a new secret') : t('webforms', 'Generate a secret') }}
						</NcButton>
					</div>
					<p v-if="!webhook.secretRef" class="af-action__hint">
						{{ t('webforms', 'Without a secret the request is sent unsigned.') }}
					</p>
				</div>
			</template>

			<!-- When -->
			<div class="af-action__group">
				<NcCheckboxRadioSwitch type="switch"
					:model-value="conditional"
					:disabled="!conditional && !canAddWhen"
					@update:model-value="setWhenEnabled">
					{{ t('webforms', 'Only when the answers match') }}
				</NcCheckboxRadioSwitch>
				<ConditionGroup v-if="whenGroup"
					:node="whenGroup"
					:depth="1"
					:definition="definition"
					:sources="sources"
					:forbidden="noForbidden"
					:removable="false"
					@update="(when) => emit('update', setWhen(action, when))"
					@remove="setWhenEnabled(false)" />
			</div>

			<div class="af-action__footer">
				<NcButton variant="tertiary" :disabled="index === 0" :aria-label="t('webforms', 'Move action up')" @click="emit('move', -1)">
					<template #icon>
						<Icon :path="ARROW_UP" />
					</template>
				</NcButton>
				<NcButton variant="tertiary" :disabled="index === count - 1" :aria-label="t('webforms', 'Move action down')" @click="emit('move', 1)">
					<template #icon>
						<Icon :path="ARROW_DOWN" />
					</template>
				</NcButton>
				<NcButton variant="tertiary" :disabled="locked" @click="emit('remove')">
					<template #icon>
						<Icon :path="TRASH" />
					</template>
					{{ t('webforms', 'Delete action') }}
				</NcButton>
			</div>
		</div>
	</article>
</template>

<style scoped>
.af-action {
	border: 1px solid transparent;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	transition: border-color 0.12s ease;
}

.af-action--error {
	border-color: var(--color-error);
}

.af-action__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	position: relative;
	width: 100%;
	box-sizing: border-box;
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 8) calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 3);
	border: 0;
	border-radius: var(--border-radius-large);
	background: transparent;
	color: var(--color-main-text);
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.af-action__head:hover {
	background: var(--color-background-dark);
}

.af-action__head:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: -2px;
}

.af-action__title {
	font-size: 16px;
	font-weight: 600;
}

.af-action__summary {
	flex: 1 1 100%;
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.af-action__chip {
	padding: 0 calc(var(--default-grid-baseline) * 1.5);
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 12px;
	line-height: 20px;
}

.af-action__chip--warn {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.af-action__chevron {
	position: absolute;
	inset-inline-end: calc(var(--default-grid-baseline) * 4);
	top: calc(var(--default-grid-baseline) * 4.5);
	width: 8px;
	height: 8px;
	border-right: 2px solid var(--color-text-maxcontrast);
	border-bottom: 2px solid var(--color-text-maxcontrast);
	transform: rotate(45deg);
	transition: transform 0.15s ease;
}

.af-action__chevron--open {
	transform: rotate(225deg);
}

.af-action__body {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	margin: 0 calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 3) 0;
	border-top: 1px solid var(--color-border);
}

/* Each part of the body is its own labelled block, set off by a thin line. */
.af-action__group {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding-top: calc(var(--default-grid-baseline) * 3);
	border-top: 1px solid var(--color-border);
}

.af-action__caption {
	font-weight: 500;
}

.af-action__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

/*
 * A box whose placeholder is the default that applies when it is left empty. Nextcloud's fields hide
 * the placeholder until they have focus and park the label inside the box instead, so here the label
 * always sits on the border, as it does for a filled field, and the placeholder always shows.
 */
.af-action__defaulted :deep(.input-field__input:not(:focus)::placeholder) {
	opacity: 1 !important;
}

.af-action__defaulted :deep(.input-field__input + .input-field__label) {
	--input-label-font-size: 13px;
	line-height: 1.5;
	inset-block-start: calc(-1.5 * var(--input-label-font-size) / 2);
	font-weight: var(--font-weight-element, 500);
	border-radius: var(--default-grid-baseline) var(--default-grid-baseline) 0 0;
	background-color: var(--color-main-background);
	padding-inline: var(--default-grid-baseline);
	margin-inline: calc(var(--input-padding-start) - var(--default-grid-baseline)) calc(var(--input-padding-end) - var(--default-grid-baseline));
}

.af-action__inserts,
.af-action__row,
.af-action__footer {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.af-action__footer {
	justify-content: flex-end;
	padding-top: calc(var(--default-grid-baseline) * 3);
	border-top: 1px solid var(--color-border);
}

.af-action__ok {
	color: var(--color-success-text);
}

.af-action__secret {
	display: block;
	margin: var(--default-grid-baseline) 0;
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-element);
	background: var(--color-background-dark);
	color: var(--color-main-text);
	overflow-wrap: anywhere;
	user-select: all;
}
.af-recipient {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	min-width: 0;
}

.af-recipient__label {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.af-recipient__kind {
	flex: 0 0 auto;
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}
</style>
