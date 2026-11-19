<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Controller;

use OCA\Webforms\AppInfo\Application;
use OCA\Webforms\Exception\ForbiddenException;
use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Service\CollaboratorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Who a form is shared with (docs/SCHEMA.md section 22.4). Managing it is for the owner; leaving is for
 * anyone it was shared with.
 */
class CollaboratorController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly CollaboratorService $service,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}/shares')]
	public function index(int $id): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse($this->service->list($this->userId(), $id)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/forms/{id}/shares')]
	public function set(int $id, string $type = '', string $with = '', string $role = ''): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse($this->service->set($this->userId(), $id, $type, $with, $role)));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/forms/{id}/shares/{shareId}', requirements: ['shareId' => '\d+'])]
	public function remove(int $id, int $shareId): JSONResponse {
		return $this->guard(function () use ($id, $shareId): JSONResponse {
			$this->service->remove($this->userId(), $id, $shareId);
			return new JSONResponse(['ok' => true]);
		});
	}

	/**
	 * The caller gives up their own access to a form shared with them.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/forms/{id}/shares/me')]
	public function leave(int $id): JSONResponse {
		return $this->guard(function () use ($id): JSONResponse {
			$this->service->leave($this->userId(), $id);
			return new JSONResponse(['ok' => true]);
		});
	}

	/**
	 * Accounts and groups to share with, found by what was typed. Limited per person, since each call asks
	 * the server's account and group search.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}/sharees')]
	public function sharees(int $id, string $search = ''): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(['sharees' => $this->service->find($this->userId(), $id, $search)]));
	}

	private function userId(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}

	/**
	 * @param callable(): JSONResponse $action
	 */
	private function guard(callable $action): JSONResponse {
		try {
			return $action();
		} catch (NotFoundException) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		} catch (ValidationException $e) {
			return new JSONResponse(['error' => 'validation', 'errors' => $e->getErrors()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}
}
