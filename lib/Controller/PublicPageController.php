<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Controller;

use OCA\Webforms\AppInfo\Application;
use OCA\Webforms\Db\Form;
use OCA\Webforms\Db\FormMapper;
use OCA\Webforms\Db\VersionMapper;
use OCA\Webforms\Forms\ImageSource;
use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Security\Captcha\CaptchaProvider;
use OCA\Webforms\Security\Captcha\CaptchaSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
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
		private readonly FormMapper $forms,
		private readonly VersionMapper $versions,
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
		$response = new PublicTemplateResponse(Application::APP_ID, 'public', ['token' => $token, 'embed' => false]);
		$response->setFooterVisible(false);
		$policy = new ContentSecurityPolicy();
		$captcha = $this->allowCaptchaProvider($policy);
		if ($this->allowRemoteImages($policy, $token) || $captcha) {
			$response->setContentSecurityPolicy($policy);
		}
		return $response;
	}

	/**
	 * The same form without Nextcloud's header, for a frame on another website (docs/SCHEMA.md section
	 * 18.3). Browsers refuse to frame a Nextcloud page unless it says who may, so this names the sites
	 * the form's owner allowed, or any site. For a form that does not allow embedding nothing is
	 * opened up and the browser keeps refusing, the same as for an address that leads nowhere.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/f/{token}/embed')]
	public function embed(string $token): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-public');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-public');
		$response = new TemplateResponse(Application::APP_ID, 'public', ['token' => $token, 'embed' => true], TemplateResponse::RENDER_AS_BASE);
		$policy = new ContentSecurityPolicy();
		$this->allowCaptchaProvider($policy);
		$this->allowRemoteImages($policy, $token);
		foreach ($this->frameAncestors($token) as $ancestor) {
			$policy->addAllowedFrameAncestorDomain($ancestor);
		}
		$response->setContentSecurityPolicy($policy);
		return $response;
	}

	/**
	 * @return list<string> who may frame this form: nobody (empty), the listed origins, or `*`
	 */
	private function frameAncestors(string $token): array {
		try {
			$form = $this->forms->findByToken($token);
		} catch (NotFoundException) {
			return [];
		}
		if (!$form->isEmbeddable()) {
			return [];
		}
		$origins = $form->getEmbedOriginList();
		return $origins === [] ? ['*'] : $origins;
	}

	/**
	 * Image blocks may show a picture from another site (docs/SCHEMA.md section 4.9). Only the hosts the
	 * published form names are opened up, and only for pictures. An address that leads to no published
	 * form opens nothing.
	 */
	private function allowRemoteImages(ContentSecurityPolicy $policy, string $token): bool {
		try {
			$form = $this->forms->findByToken($token);
			if ($form->getStatus() === Form::STATUS_UNPUBLISHED || $form->getLatestVersionId() === null) {
				return false;
			}
			$hosts = ImageSource::remoteHosts($this->versions->find($form->getLatestVersionId())->getDefinitionArray());
		} catch (\Throwable) {
			return false;
		}
		foreach ($hosts as $host) {
			$policy->addAllowedImageDomain('https://' . $host);
		}
		return $hosts !== [];
	}

	/** Turnstile and hCaptcha load a script and a frame from their own domains. Nothing else is opened up. */
	private function allowCaptchaProvider(ContentSecurityPolicy $policy): bool {
		$domains = match ($this->captcha->effectiveProvider()) {
			CaptchaProvider::TURNSTILE => ['https://challenges.cloudflare.com'],
			CaptchaProvider::HCAPTCHA => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
			default => [],
		};
		foreach ($domains as $domain) {
			$policy->addAllowedScriptDomain($domain);
			$policy->addAllowedFrameDomain($domain);
			$policy->addAllowedConnectDomain($domain);
			$policy->addAllowedStyleDomain($domain);
		}
		return $domains !== [];
	}
}
