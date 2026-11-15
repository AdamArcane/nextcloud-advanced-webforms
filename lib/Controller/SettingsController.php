<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Controller;

use OCA\Webforms\AppInfo\Application;
use OCA\Webforms\Service\PolicyService;
use OCA\Webforms\Settings\UserPreferences;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * What the administrator's policy means for the signed-in user, so the builder can hide what the
 * server does not allow instead of letting people run into a refusal at publish.
 */
class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly PolicyService $policy,
		private readonly IUserSession $userSession,
		private readonly UserPreferences $preferences,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/settings')]
	public function index(): JSONResponse {
		return new JSONResponse($this->policy->forUser((string)$this->userSession->getUser()?->getUID()));
	}

	/** The signed-in user's own preferences (Personal settings). */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/preferences')]
	public function preferences(): JSONResponse {
		return new JSONResponse($this->preferences->forUser((string)$this->userSession->getUser()?->getUID()));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/preferences')]
	public function savePreferences(bool $openPublishedOnResponses): JSONResponse {
		$userId = (string)$this->userSession->getUser()?->getUID();
		$this->preferences->setOpenPublishedOnResponses($userId, $openPublishedOnResponses);
		return new JSONResponse($this->preferences->forUser($userId));
	}
}
