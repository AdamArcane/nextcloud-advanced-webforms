<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { onMounted, ref, watch } from 'vue'

// A drawing pad. The answer is the drawing as a small PNG in a `data:` address (docs/SCHEMA.md 4.10),
// transparent, so it can be shown on any background. A mouse, a finger or a pen all draw.

const props = defineProps<{
	modelValue: string
	describedBy?: string
	invalid?: boolean
	required?: boolean
	/** The builder canvas shows the pad but does not take a drawing. */
	disabled?: boolean
}>()

const emit = defineEmits<{
	'update:modelValue': [value: string]
}>()

const WIDTH = 600
const HEIGHT = 200

const canvas = ref<HTMLCanvasElement | null>(null)
let drawing = false

function context(): CanvasRenderingContext2D | null {
	return canvas.value?.getContext('2d') ?? null
}

function setup() {
	const ctx = context()
	if (ctx) {
		ctx.lineWidth = 2.5
		ctx.lineCap = 'round'
		ctx.lineJoin = 'round'
		ctx.strokeStyle = '#111111'
	}
}

/** Paint a given answer back, such as one restored by the form. */
function paint(source: string) {
	const ctx = context()
	if (!ctx) {
		return
	}
	ctx.clearRect(0, 0, WIDTH, HEIGHT)
	if (source !== '') {
		const image = new Image()
		image.onload = () => ctx.drawImage(image, 0, 0, WIDTH, HEIGHT)
		image.src = source
	}
}

onMounted(() => {
	setup()
	paint(props.modelValue)
})

// A change that did not come from drawing, such as clearing from outside, is painted back.
let own = ''
watch(() => props.modelValue, (value) => {
	if (value !== own) {
		paint(value)
	}
})

function point(event: PointerEvent): [number, number] {
	const box = canvas.value!.getBoundingClientRect()
	return [(event.clientX - box.left) * (WIDTH / box.width), (event.clientY - box.top) * (HEIGHT / box.height)]
}

function down(event: PointerEvent) {
	if (props.disabled) {
		return
	}
	const ctx = context()
	if (!ctx) {
		return
	}
	drawing = true
	canvas.value!.setPointerCapture(event.pointerId)
	const [x, y] = point(event)
	ctx.beginPath()
	ctx.moveTo(x, y)
	// A tap leaves a dot.
	ctx.lineTo(x + 0.01, y + 0.01)
	ctx.stroke()
}

function move(event: PointerEvent) {
	const ctx = context()
	if (!drawing || !ctx) {
		return
	}
	const [x, y] = point(event)
	ctx.lineTo(x, y)
	ctx.stroke()
}

function up() {
	if (!drawing) {
		return
	}
	drawing = false
	own = canvas.value!.toDataURL('image/png')
	emit('update:modelValue', own)
}

function clear() {
	const ctx = context()
	ctx?.clearRect(0, 0, WIDTH, HEIGHT)
	own = ''
	emit('update:modelValue', '')
}
</script>

<template>
	<div class="af-signature">
		<canvas ref="canvas"
			class="af-signature__pad"
			:class="{ 'af-signature__pad--invalid': invalid }"
			:width="WIDTH"
			:height="HEIGHT"
			role="img"
			:aria-label="modelValue === '' ? t('webforms', 'Signature pad, empty') : t('webforms', 'Signature pad, signed')"
			:aria-describedby="describedBy"
			:aria-required="required || undefined"
			@pointerdown.prevent="down"
			@pointermove="move"
			@pointerup="up"
			@pointercancel="up" />
		<div class="af-signature__bar">
			<span class="af-signature__hint">{{ t('webforms', 'Draw your signature above.') }}</span>
			<button type="button" class="af-signature__clear" :disabled="disabled || modelValue === ''" @click="clear">
				{{ t('webforms', 'Clear') }}
			</button>
		</div>
	</div>
</template>

<style scoped>
.af-signature__pad {
	display: block;
	width: 100%;
	max-width: 600px;
	aspect-ratio: 3 / 1;
	box-sizing: border-box;
	/* Ink is dark, so the pad is light whatever the theme is. */
	background: #fff;
	border: 2px solid var(--color-border-maxcontrast, var(--color-border-dark));
	border-radius: var(--border-radius-element, 8px);
	cursor: crosshair;
	touch-action: none;
}

.af-signature__pad--invalid {
	border-color: var(--color-error);
}

.af-signature__bar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	max-width: 600px;
	margin-top: 4px;
}

.af-signature__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.af-signature__clear {
	min-height: var(--default-clickable-area, 34px);
}
</style>
