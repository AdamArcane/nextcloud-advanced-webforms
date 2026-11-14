<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Security\Captcha;

/** Checks a Cloudflare Turnstile or hCaptcha answer with the provider's verify endpoint. */
class RemoteCaptcha {
	public const ENDPOINTS = [
		CaptchaProvider::TURNSTILE => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		CaptchaProvider::HCAPTCHA => 'https://api.hcaptcha.com/siteverify',
	];

	private const MAX_RESPONSE_LENGTH = 4096;

	public function __construct(
		private readonly CaptchaHttp $http,
	) {
	}

	/**
	 * Fails closed: if the provider cannot be reached, the submission is not accepted.
	 */
	public function verify(string $provider, string $secret, string $siteKey, string $response, string $remoteIp): bool {
		$endpoint = self::ENDPOINTS[$provider] ?? null;
		if ($endpoint === null || $response === '' || strlen($response) > self::MAX_RESPONSE_LENGTH) {
			return false;
		}
		$fields = ['secret' => $secret, 'response' => $response];
		if ($remoteIp !== '') {
			$fields['remoteip'] = $remoteIp;
		}
		if ($provider === CaptchaProvider::HCAPTCHA) {
			$fields['sitekey'] = $siteKey;
		}
		try {
			$answer = $this->http->postForm($endpoint, $fields, 5);
		} catch (\Throwable) {
			// The adapter has already logged why.
			return false;
		}
		return ($answer['success'] ?? false) === true;
	}
}
