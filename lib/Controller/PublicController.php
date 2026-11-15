<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Controller;

use OCA\Webforms\AppInfo\Application;
use OCA\Webforms\Exception\FormClosedException;
use OCA\Webforms\Exception\LoginRequiredException;
use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Security\Captcha\CaptchaService;
use OCA\Webforms\Service\SubmissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Respondent API, reachable without an account unless the form requires
 * login. Submitting keeps CSRF protection on: the public page is served by
 * this app, so its script has a request token.
 */
class PublicController extends Controller {
	/** Upper bound for the decoded values, a cheap guard before any validation. */
	private const MAX_VALUES_BYTES = 200 * 1024;

	public function __construct(
		IRequest $request,
		private readonly SubmissionService $service,
		private readonly IUserSession $userSession,
		private readonly CaptchaService $captcha,
		private readonly ITimeFactory $time,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/{token}')]
	public function show(string $token): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->getPublic($token, $this->userId()),
		));
	}

	/**
	 * @param array<string, mixed> $values
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 20, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/{token}/submit')]
	public function submit(
		string $token,
		int $versionId = 0,
		array $values = [],
		string $fillToken = '',
		string $hp = '',
		string $captcha = '',
	): JSONResponse {
		if (strlen((string)json_encode($values)) > self::MAX_VALUES_BYTES) {
			return new JSONResponse(['error' => 'too_large'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->submit($token, $this->userId(), $versionId, $values, $fillToken, $hp, $captcha, $this->request->getRemoteAddress()),
		));
	}

	/**
	 * A fresh challenge for the built-in captcha. It says nothing about any form, so a form that
	 * does not exist answers the same as one that does.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/captcha/challenge')]
	public function challenge(): JSONResponse {
		$challenge = $this->captcha->challenge($this->time->getTime());
		return $challenge === null
			? new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND)
			: new JSONResponse($challenge);
	}

	/**
	 * One file for an upload field. It waits on the server until the form is submitted with the
	 * id this returns. Limits are stricter than for a submission because a file costs more to take.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/{token}/upload')]
	public function upload(string $token, int $versionId = 0, string $fieldId = '', string $fillToken = ''): JSONResponse {
		$file = $this->request->getUploadedFile('file');
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->upload($token, $this->userId(), $versionId, $fieldId, $fillToken, is_array($file) ? $file : null),
			Http::STATUS_CREATED,
		));
	}

	private function userId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	/**
	 * @param callable(): JSONResponse $action
	 */
	private function guard(callable $action): JSONResponse {
		try {
			return $action();
		} catch (NotFoundException) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		} catch (FormClosedException $e) {
			return new JSONResponse(['error' => 'closed', 'message' => $e->getClosedMessage()], Http::STATUS_GONE);
		} catch (LoginRequiredException) {
			return new JSONResponse(['error' => 'login_required'], Http::STATUS_UNAUTHORIZED);
		} catch (ValidationException $e) {
			return new JSONResponse(['error' => 'validation', 'errors' => $e->getErrors()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}
}
