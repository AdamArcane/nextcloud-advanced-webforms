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
use OCA\Webforms\Service\FolderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The caller's own folders (docs/SCHEMA.md section 24.2).
 */
class FolderController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly FolderService $service,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/folders')]
	public function index(): JSONResponse {
		return new JSONResponse(['folders' => $this->service->list($this->userId())]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/folders')]
	public function create(string $name = ''): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->create($this->userId(), $name),
			Http::STATUS_CREATED,
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/folders/{folderId}', requirements: ['folderId' => '\d+'])]
	public function rename(int $folderId, string $name = ''): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse($this->service->rename($this->userId(), $folderId, $name)));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/folders/{folderId}', requirements: ['folderId' => '\d+'])]
	public function destroy(int $folderId): JSONResponse {
		return $this->guard(function () use ($folderId): JSONResponse {
			$this->service->delete($this->userId(), $folderId);
			return new JSONResponse(['ok' => true]);
		});
	}

	/** Put a form in a folder, or with a `folderId` of null take it out. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/forms/{id}/folder', requirements: ['id' => '\d+'])]
	public function move(int $id, ?int $folderId = null): JSONResponse {
		return $this->guard(function () use ($id, $folderId): JSONResponse {
			$this->service->moveForm($this->userId(), $id, $folderId);
			return new JSONResponse(['folderId' => $folderId]);
		});
	}

	private function userId(): string {
		// #[NoAdminRequired] still requires a logged-in user, so this is set.
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
