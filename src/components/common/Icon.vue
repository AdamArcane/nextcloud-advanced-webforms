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
	// Inside NcAction* menus pass false: the menu supplies its own icon box, which keeps
	// icons centred and aligned with the label.
	inline?: boolean
}>(), { size: 20, inline: true })
</script>

<template>
	<NcIconSvgWrapper :path="path" :size="size" :inline="inline" />
</template>
