<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'

// Marks a response that is on the waiting list (docs/SCHEMA.md section 3.2). It is not a status.
</script>

<template>
	<span class="af-waitlist-tag">{{ t('webforms', 'Waiting list') }}</span>
</template>

<style scoped>
.af-waitlist-tag {
	display: inline-flex;
	align-items: center;
	padding: 0 10px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-pill, 999px);
	color: var(--color-main-text);
	font-size: 12px;
	white-space: nowrap;
}
</style>
