<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { CaptchaConfig } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { fetchCaptchaChallenge } from '../../api/public.ts'
import { solveChallenge } from '../../captcha/pow.ts'

const props = defineProps<{
	config: CaptchaConfig
}>()

type RemoteApi = {
	render: (element: HTMLElement, options: Record<string, unknown>) => string
	reset: (id?: string) => void
}

const SCRIPTS: Record<string, { url: string, global: 'turnstile' | 'hcaptcha' }> = {
	turnstile: { url: 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', global: 'turnstile' },
	hcaptcha: { url: 'https://js.hcaptcha.com/1/api.js?render=explicit', global: 'hcaptcha' },
}

/** A built-in solution expires on the server after ten minutes; renew before that. */
const RENEW_AFTER_MS = 8 * 60 * 1000

const widget = ref<HTMLElement | null>(null)
const status = ref<'working' | 'ready' | 'failed'>('working')

let pending: Promise<string | null> = Promise.resolve(null)
let remoteToken: string | null = null
let remoteWidgetId: string | undefined
let renewTimer: ReturnType<typeof setTimeout> | undefined
let controller: AbortController | undefined
let gone = false

function startBuiltin() {
	controller?.abort()
	controller = new AbortController()
	const signal = controller.signal
	status.value = 'working'
	clearTimeout(renewTimer)
	pending = (async () => {
		try {
			const solution = await solveChallenge(await fetchCaptchaChallenge(), signal)
			if (!gone && !signal.aborted) {
				status.value = 'ready'
				renewTimer = setTimeout(startBuiltin, RENEW_AFTER_MS)
			}
			return solution
		} catch {
			if (!gone && !signal.aborted) {
				status.value = 'failed'
			}
			return null
		}
	})()
}

function loadScript(provider: string): Promise<RemoteApi> {
	const { url, global } = SCRIPTS[provider]
	const existing = (window as unknown as Record<string, RemoteApi | undefined>)[global]
	if (existing) {
		return Promise.resolve(existing)
	}
	return new Promise((resolve, reject) => {
		const script = document.createElement('script')
		script.src = url
		script.async = true
		script.onload = () => {
			const api = (window as unknown as Record<string, RemoteApi | undefined>)[global]
			api ? resolve(api) : reject(new Error('missing'))
		}
		script.onerror = () => reject(new Error('blocked'))
		document.head.appendChild(script)
	})
}

async function startRemote() {
	status.value = 'working'
	try {
		const api = await loadScript(props.config.provider)
		if (gone || !widget.value) {
			return
		}
		remoteWidgetId = api.render(widget.value, {
			sitekey: props.config.siteKey,
			callback: (token: string) => {
				remoteToken = token
				status.value = 'ready'
			},
			'expired-callback': () => {
				remoteToken = null
			},
			'error-callback': () => {
				remoteToken = null
			},
		})
		status.value = 'ready'
	} catch {
		status.value = 'failed'
	}
}

onMounted(() => {
	if (props.config.provider === 'builtin') {
		startBuiltin()
	} else {
		void startRemote()
	}
})

onBeforeUnmount(() => {
	gone = true
	controller?.abort()
	clearTimeout(renewTimer)
})

/** The answer to send with the form, or null when the check has not been passed. */
async function response(): Promise<string | null> {
	return props.config.provider === 'builtin' ? await pending : remoteToken
}

/** An answer works once. Get a fresh check after each attempt. */
function reset() {
	if (props.config.provider === 'builtin') {
		startBuiltin()
		return
	}
	remoteToken = null
	const api = (window as unknown as Record<string, RemoteApi | undefined>)[SCRIPTS[props.config.provider].global]
	api?.reset(remoteWidgetId)
}

function retry() {
	if (props.config.provider === 'builtin') {
		startBuiltin()
	} else {
		void startRemote()
	}
}

defineExpose({ response, reset })
</script>

<template>
	<div class="af-captcha">
		<div v-if="config.provider !== 'builtin'" ref="widget" class="af-captcha__widget" />
		<p v-if="config.provider === 'builtin' && status === 'working'" class="af-captcha__status" role="status">
			{{ t('advancedforms', 'Checking that you are not a bot…') }}
		</p>
		<p v-else-if="status === 'failed'" class="af-captcha__status af-captcha__status--failed" role="alert">
			{{ t('advancedforms', 'The spam check could not be loaded.') }}
			<NcButton variant="tertiary" @click="retry">
				{{ t('advancedforms', 'Try again') }}
			</NcButton>
		</p>
	</div>
</template>

<style scoped>
.af-captcha {
	margin-top: 16px;
}

.af-captcha__status {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.af-captcha__status--failed {
	color: var(--color-error-text);
}
</style>
