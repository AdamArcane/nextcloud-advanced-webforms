<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
</script>

<template>
	<NcContent app-name="advancedforms">
		<NcAppContent>
			<NcEmptyContent
				:name="t('advancedforms', 'Advanced Forms')"
				:description="t('advancedforms', 'The form builder is not available yet.')" />
		</NcAppContent>
	</NcContent>
</template>
