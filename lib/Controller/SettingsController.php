<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Controller;

use OCA\Webforms\AppInfo\Application;
use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Service\PolicyService;
use OCA\Webforms\Settings\UserPreferences;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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

	/** The signed-in user's own preferences (docs/SCHEMA.md section 16.1). */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/preferences')]
	public function preferences(): JSONResponse {
		return new JSONResponse($this->preferences->forUser((string)$this->userSession->getUser()?->getUID()));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/preferences')]
	public function savePreferences(
		?bool $openPublishedOnResponses = null,
		?string $newFormLabelPosition = null,
		?string $newFormAccentColor = null,
		?bool $newFormHideTitle = null,
		?bool $newFormCaptcha = null,
		?string $responsesView = null,
		?bool $responsesSummary = null,
	): JSONResponse {
		$userId = (string)$this->userSession->getUser()?->getUID();
		// Only what was sent is changed, so one switch never rewrites the others.
		$patch = array_filter([
			'openPublishedOnResponses' => $openPublishedOnResponses,
			'newFormLabelPosition' => $newFormLabelPosition,
			'newFormAccentColor' => $newFormAccentColor,
			'newFormHideTitle' => $newFormHideTitle,
			'newFormCaptcha' => $newFormCaptcha,
			'responsesView' => $responsesView,
			'responsesSummary' => $responsesSummary,
		], static fn (mixed $value): bool => $value !== null);
		try {
			$this->preferences->save($userId, $patch);
		} catch (ValidationException $e) {
			return new JSONResponse(['error' => 'validation', 'errors' => $e->getErrors()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		return new JSONResponse($this->preferences->forUser($userId));
	}
}
