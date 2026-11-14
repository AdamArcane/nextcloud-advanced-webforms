<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Controller;

use OCA\AdvancedForms\Actions\Adapter\NextcloudSecrets;
use OCA\AdvancedForms\AppInfo\Application;
use OCA\AdvancedForms\Exception\NotFoundException;
use OCA\AdvancedForms\Exception\ValidationException;
use OCA\AdvancedForms\Service\FormService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Owner API for managing forms and reading entries. Any logged-in user may
 * create forms; every call is scoped to the forms that user owns.
 */
class FormController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly FormService $service,
		private readonly IUserSession $userSession,
		private readonly NextcloudSecrets $secrets,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms')]
	public function index(): JSONResponse {
		$forms = array_map(
			fn ($form): array => $this->service->toArray($form),
			$this->service->list($this->userId()),
		);
		return new JSONResponse(['forms' => $forms]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/forms')]
	public function create(string $title = ''): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->toArray($this->service->create($this->userId(), $title), true),
			Http::STATUS_CREATED,
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}')]
	public function show(int $id): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->toArray($this->service->get($this->userId(), $id), true),
		));
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/forms/{id}/draft')]
	public function saveDraft(int $id, array $definition = []): JSONResponse {
		return $this->guard(function () use ($id, $definition): JSONResponse {
			$saved = $this->service->saveDraft($this->userId(), $id, $definition);
			return new JSONResponse([
				'form' => $this->service->toArray($saved['form'], true),
				// Not blocking for a draft, but publishing will refuse these.
				'errors' => $saved['errors'],
			]);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/forms/{id}/publish')]
	public function publish(int $id): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->toArray($this->service->publish($this->userId(), $id), true),
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/forms/{id}/status')]
	public function setStatus(int $id, int $status = -1): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->toArray($this->service->setStatus($this->userId(), $id, $status)),
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/forms/{id}')]
	public function destroy(int $id): JSONResponse {
		return $this->guard(function () use ($id): JSONResponse {
			$this->service->delete($this->userId(), $id);
			return new JSONResponse([], Http::STATUS_NO_CONTENT);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}/entries')]
	public function entries(int $id, int $limit = 50, int $offset = 0): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->listEntries($this->userId(), $id, $limit, $offset),
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/forms/{id}/entries/{entryId}')]
	public function destroyEntry(int $id, int $entryId): JSONResponse {
		return $this->guard(function () use ($id, $entryId): JSONResponse {
			$this->service->deleteEntry($this->userId(), $id, $entryId);
			return new JSONResponse([], Http::STATUS_NO_CONTENT);
		});
	}

	/**
	 * Create or replace a webhook signing secret. The value is in this response
	 * only; it is stored encrypted and cannot be read back.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/forms/{id}/webhook-secret')]
	public function createWebhookSecret(int $id, string $ref = ''): JSONResponse {
		return $this->guard(function () use ($id, $ref): JSONResponse {
			$form = $this->service->get($this->userId(), $id);
			$response = new JSONResponse($this->secrets->generate($form->getId(), $ref === '' ? null : $ref), Http::STATUS_CREATED);
			$response->addHeader('Cache-Control', 'no-store');
			return $response;
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}/webhook-secret/{ref}')]
	public function webhookSecretExists(int $id, string $ref): JSONResponse {
		return $this->guard(function () use ($id, $ref): JSONResponse {
			$form = $this->service->get($this->userId(), $id);
			return new JSONResponse(['exists' => $this->secrets->exists($form->getId(), $ref)]);
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
		} catch (ValidationException $e) {
			return new JSONResponse(['error' => 'validation', 'errors' => $e->getErrors()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}
}
