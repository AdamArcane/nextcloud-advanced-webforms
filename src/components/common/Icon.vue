<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

// Decorative by default: the text or aria-label next to an icon already says what it means.
withDefaults(defineProps<{
	path: string
	size?: number
}>(), { size: 20 })
</script>

<template>
	<NcIconSvgWrapper :path="path" :size="size" inline />
</template>
