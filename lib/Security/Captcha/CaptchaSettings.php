<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Security\Captcha;

/**
 * The administrator's captcha choice. The provider and site key are plain app config;
 * the secret is a sensitive value (encrypted at rest) and is never sent to a browser.
 */
class CaptchaSettings {
	private const PROVIDER = 'captcha_provider';
	private const SITE_KEY = 'captcha_site_key';
	private const SECRET = 'captcha_secret';

	public function __construct(
		private readonly ConfigStore $config,
	) {
	}

	public function provider(): string {
		$provider = $this->config->get(self::PROVIDER);
		// Never configured means the built-in check, which needs nothing from the administrator.
		return in_array($provider, CaptchaProvider::ALL, true) ? $provider : CaptchaProvider::BUILTIN;
	}

	public function siteKey(): string {
		return $this->config->get(self::SITE_KEY);
	}

	public function secret(): string {
		return $this->config->get(self::SECRET, true);
	}

	/**
	 * A provider that needs keys is only usable once it has them, so a half-finished setup
	 * never locks respondents out: it counts as no check.
	 */
	public function effectiveProvider(): string {
		$provider = $this->provider();
		if (in_array($provider, CaptchaProvider::REMOTE, true) && ($this->siteKey() === '' || $this->secret() === '')) {
			return CaptchaProvider::NONE;
		}
		return $provider;
	}

	/**
	 * @param string|null $secret null keeps the stored secret
	 */
	public function save(string $provider, string $siteKey, ?string $secret): void {
		$this->config->set(self::PROVIDER, $provider);
		$this->config->set(self::SITE_KEY, $siteKey);
		if ($secret !== null) {
			$this->config->set(self::SECRET, $secret, true);
		}
	}
}
