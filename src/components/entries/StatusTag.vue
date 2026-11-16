<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ResponseStatus } from '../../types.ts'

import { computed } from 'vue'
import { statusLabel } from '../../entries/statuses.ts'
import { statusColor } from '../../entries/statusColors.ts'

// A status as a small coloured tag: a dot and the name, on a wash of the status's own colour.
const props = defineProps<{
	status: ResponseStatus
}>()

const color = computed(() => statusColor(props.status.color))
</script>

<template>
	<span class="af-tag" :style="{ '--af-tag-color': color }">
		<span class="af-tag__dot" aria-hidden="true" />
		<span class="af-tag__name">{{ statusLabel(status) }}</span>
	</span>
</template>

<style scoped>
.af-tag {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	max-width: 100%;
	padding: 0 10px 0 8px;
	border: 1px solid var(--af-tag-color);
	border-radius: var(--border-radius-pill, 999px);
	/* A wash of the colour over whatever is behind it, so it works on light and dark backgrounds. */
	background: color-mix(in srgb, var(--af-tag-color) 16%, transparent);
	color: var(--color-main-text);
	font-size: 12px;
	line-height: 20px;
	white-space: nowrap;
}

.af-tag__dot {
	flex: 0 0 auto;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: var(--af-tag-color);
}

.af-tag__name {
	overflow: hidden;
	text-overflow: ellipsis;
}
</style>
