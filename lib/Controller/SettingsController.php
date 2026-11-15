<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Controller;

use OCA\Webforms\AppInfo\Application;
use OCA\Webforms\Service\PolicyService;
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
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/settings')]
	public function index(): JSONResponse {
		return new JSONResponse($this->policy->forUser((string)$this->userSession->getUser()?->getUID()));
	}
}
