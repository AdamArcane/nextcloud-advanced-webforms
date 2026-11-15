<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Controller;

use OCA\Webforms\AppInfo\Application;
use OCA\Webforms\Security\Captcha\CaptchaProvider;
use OCA\Webforms\Security\Captcha\CaptchaSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\IRequest;
use OCP\Util;

/**
 * The public page for a form. It is only a shell: the script loads the form
 * through the public API, which answers 404, closed, or login required.
 * Nothing about the form is rendered here, so an unknown or unpublished token
 * reveals nothing.
 */
class PublicPageController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly CaptchaSettings $captcha,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/f/{token}')]
	public function show(string $token): PublicTemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-public');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-public');
		$response = new PublicTemplateResponse(Application::APP_ID, 'public', ['token' => $token]);
		$response->setFooterVisible(false);
		$this->allowCaptchaProvider($response);
		return $response;
	}

	/** Turnstile and hCaptcha load a script and a frame from their own domains. Nothing else is opened up. */
	private function allowCaptchaProvider(PublicTemplateResponse $response): void {
		$domains = match ($this->captcha->effectiveProvider()) {
			CaptchaProvider::TURNSTILE => ['https://challenges.cloudflare.com'],
			CaptchaProvider::HCAPTCHA => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
			default => [],
		};
		if ($domains === []) {
			return;
		}
		$policy = new ContentSecurityPolicy();
		foreach ($domains as $domain) {
			$policy->addAllowedScriptDomain($domain);
			$policy->addAllowedFrameDomain($domain);
			$policy->addAllowedConnectDomain($domain);
			$policy->addAllowedStyleDomain($domain);
		}
		$response->setContentSecurityPolicy($policy);
	}
}
