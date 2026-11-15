<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Security\Captcha;

/**
 * The spam check an administrator can switch on for the whole server.
 * `builtin` is a proof-of-work check that needs no account and no third party.
 */
final class CaptchaProvider {
	public const NONE = 'none';
	public const BUILTIN = 'builtin';
	public const TURNSTILE = 'turnstile';
	public const HCAPTCHA = 'hcaptcha';

	public const ALL = [self::NONE, self::BUILTIN, self::TURNSTILE, self::HCAPTCHA];

	/** Providers that need a site key and a secret from the provider's dashboard. */
	public const REMOTE = [self::TURNSTILE, self::HCAPTCHA];
}
