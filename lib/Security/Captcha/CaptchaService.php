<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Security\Captcha;

/**
 * One place that answers "does this form need a check, and did the respondent pass it".
 * A check applies when the administrator picked a provider, the form has not turned it off
 * (`settings.captcha === false`), and the respondent is not signed in to Nextcloud.
 */
class CaptchaService {
	public function __construct(
		private readonly CaptchaSettings $settings,
		private readonly ProofOfWork $pow,
		private readonly RemoteCaptcha $remote,
	) {
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	public function provider(array $definition, ?string $userId): string {
		$formSettings = is_array($definition['settings'] ?? null) ? $definition['settings'] : [];
		if ($userId !== null || ($formSettings['captcha'] ?? true) === false) {
			return CaptchaProvider::NONE;
		}
		return $this->settings->effectiveProvider();
	}

	/**
	 * What the public page needs to show the check, or null when there is none.
	 *
	 * @param array<string, mixed> $definition
	 * @return array{provider: string, siteKey: string}|null
	 */
	public function publicConfig(array $definition, ?string $userId): ?array {
		$provider = $this->provider($definition, $userId);
		if ($provider === CaptchaProvider::NONE) {
			return null;
		}
		return [
			'provider' => $provider,
			'siteKey' => in_array($provider, CaptchaProvider::REMOTE, true) ? $this->settings->siteKey() : '',
		];
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	public function passes(array $definition, ?string $userId, string $response, string $remoteIp, int $now): bool {
		$provider = $this->provider($definition, $userId);
		return match ($provider) {
			CaptchaProvider::NONE => true,
			CaptchaProvider::BUILTIN => $this->pow->verify($response, $now),
			default => $this->remote->verify($provider, $this->settings->secret(), $this->settings->siteKey(), $response, $remoteIp),
		};
	}

	/**
	 * A new built-in challenge. Only meaningful when the built-in check is the active one.
	 *
	 * @return array<string, mixed>|null
	 */
	public function challenge(int $now): ?array {
		if ($this->settings->effectiveProvider() !== CaptchaProvider::BUILTIN) {
			return null;
		}
		return $this->pow->issue($now);
	}
}
