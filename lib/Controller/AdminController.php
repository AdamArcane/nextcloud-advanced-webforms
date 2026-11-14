<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Controller;

use OCA\AdvancedForms\AppInfo\Application;
use OCA\AdvancedForms\Security\Captcha\CaptchaProvider;
use OCA\AdvancedForms\Security\Captcha\CaptchaSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Server-wide settings. Without `#[NoAdminRequired]` Nextcloud only lets administrators in.
 */
class AdminController extends Controller {
	private const KEY_PATTERN = '/^[A-Za-z0-9_\-.]{1,200}$/D';

	public function __construct(
		IRequest $request,
		private readonly CaptchaSettings $settings,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/captcha')]
	public function getCaptcha(): JSONResponse {
		return new JSONResponse($this->current());
	}

	/**
	 * @param string|null $secret null or empty keeps the stored secret; it is never sent back
	 */
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/admin/captcha')]
	public function setCaptcha(string $provider = '', string $siteKey = '', ?string $secret = null): JSONResponse {
		$siteKey = trim($siteKey);
		$secret = $secret === null || trim($secret) === '' ? null : trim($secret);
		if (!in_array($provider, CaptchaProvider::ALL, true)) {
			return new JSONResponse(['error' => 'invalid_provider'], Http::STATUS_BAD_REQUEST);
		}
		if (in_array($provider, CaptchaProvider::REMOTE, true)) {
			if (preg_match(self::KEY_PATTERN, $siteKey) !== 1) {
				return new JSONResponse(['error' => 'invalid_site_key'], Http::STATUS_BAD_REQUEST);
			}
			if ($secret === null && $this->settings->secret() === '') {
				return new JSONResponse(['error' => 'secret_required'], Http::STATUS_BAD_REQUEST);
			}
			if ($secret !== null && preg_match(self::KEY_PATTERN, $secret) !== 1) {
				return new JSONResponse(['error' => 'invalid_secret'], Http::STATUS_BAD_REQUEST);
			}
		} else {
			$siteKey = '';
		}
		$this->settings->save($provider, $siteKey, $secret);
		return new JSONResponse($this->current());
	}

	/**
	 * @return array{provider: string, siteKey: string, secretSet: bool}
	 */
	private function current(): array {
		return [
			'provider' => $this->settings->provider(),
			'siteKey' => $this->settings->siteKey(),
			'secretSet' => $this->settings->secret() !== '',
		];
	}
}
