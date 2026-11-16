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
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCA\Webforms\Forms\ImageSource;
use OCA\Webforms\Uploads\Ports\OwnerImagePort;
use OCP\IUserSession;

/**
 * Respondent API, reachable without an account unless the form requires
 * login. Submitting keeps CSRF protection on: the public page is served by
 * this app, so its script has a request token.
 *
 * The `embed` routes are the same calls for a form framed by another website
 * (docs/SCHEMA.md section 18.3). A browser sends no session cookie from such a
 * frame, so a request token can never match there and these routes do without
 * it. They only answer for forms that allow embedding, and always as an
 * anonymous respondent, so there is no signed-in identity to abuse.
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
		private readonly OwnerImagePort $images,
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

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/{token}/embed')]
	public function showEmbedded(string $token): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->getPublic($token, null, true),
		));
	}

	/**
	 * @param array<string, mixed> $values
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 20, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/{token}/embed/submit')]
	public function submitEmbedded(
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
			$this->service->submit($token, null, $versionId, $values, $fillToken, $hp, $captcha, $this->request->getRemoteAddress(), true),
		));
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/public/{token}/embed/upload')]
	public function uploadEmbedded(string $token, int $versionId = 0, string $fieldId = '', string $fillToken = ''): JSONResponse {
		$file = $this->request->getUploadedFile('file');
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->upload($token, null, $versionId, $fieldId, $fillToken, is_array($file) ? $file : null, true),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * A fresh challenge for the built-in captcha. It says nothing about any form, so a form that
	 * does not exist answers the same as one that does. It needs no request token: it changes
	 * nothing, and an embedded form has no token to send.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
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

	/**
	 * The picture of an image block that sits in the owner's Files (docs/SCHEMA.md section 4.9). It is
	 * named by its field, never by a path, and only for a form the caller could open.
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/{token}/image/{fieldId}')]
	public function image(string $token, string $fieldId): Response {
		return $this->sendImage($token, $fieldId, false);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/public/{token}/embed/image/{fieldId}')]
	public function imageEmbedded(string $token, string $fieldId): Response {
		return $this->sendImage($token, $fieldId, true);
	}

	private function sendImage(string $token, string $fieldId, bool $embedded): Response {
		try {
			$source = $this->service->imageSource($token, $embedded ? null : $this->userId(), $fieldId, $embedded);
		} catch (NotFoundException|FormClosedException|LoginRequiredException) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		$mime = ImageSource::mime($source['path']);
		$file = $mime === null ? null : $this->images->open($source['ownerId'], $source['path']);
		if ($file === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		$response = new StreamResponse($file['stream']);
		// The type comes from the name, which was checked against a short list; the browser must not guess another.
		$response->addHeader('Content-Type', $mime);
		$response->addHeader('Content-Length', (string)$file['size']);
		$response->addHeader('Content-Disposition', 'inline');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Cache-Control', 'private, max-age=300');
		return $response;
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
			return new JSONResponse(['error' => 'closed', 'reason' => $e->getReason(), 'message' => $e->getClosedMessage()], Http::STATUS_GONE);
		} catch (LoginRequiredException) {
			return new JSONResponse(['error' => 'login_required'], Http::STATUS_UNAUTHORIZED);
		} catch (ValidationException $e) {
			return new JSONResponse(['error' => 'validation', 'errors' => $e->getErrors()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}
}
