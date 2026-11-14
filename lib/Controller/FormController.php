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
use OCA\AdvancedForms\Service\ActionLogService;
use OCA\AdvancedForms\Service\EntryExportService;
use OCA\AdvancedForms\Service\FormService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
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
		private readonly ActionLogService $actionLog,
		private readonly EntryExportService $export,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms')]
	public function index(): JSONResponse {
		return new JSONResponse(['forms' => $this->service->listForDisplay($this->userId())]);
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
	 * Download the form as a file: `source` is `draft` or `published`. Webhook secrets are never included.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}/export')]
	public function exportForm(int $id, string $source = 'draft'): DataDownloadResponse|JSONResponse {
		try {
			$file = $this->service->exportForm($this->userId(), $id, $source);
		} catch (NotFoundException) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		} catch (ValidationException $e) {
			return new JSONResponse(['error' => 'validation', 'errors' => $e->getErrors()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$response = new DataDownloadResponse(
			json_encode($file['package'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			$file['filename'],
			'application/json; charset=utf-8',
		);
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}

	/**
	 * Create a new draft form from an export file. Never changes an existing form.
	 *
	 * @param array<string, mixed> $package
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/forms/import')]
	public function importForm(array $package = []): JSONResponse {
		return $this->guard(function () use ($package): JSONResponse {
			$imported = $this->service->importForm($this->userId(), $package);
			return new JSONResponse([
				'form' => $this->service->toArray($imported['form'], true),
				// Not blocking for a draft, but publishing will refuse these.
				'errors' => $imported['errors'],
			], Http::STATUS_CREATED);
		});
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
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/forms/{id}/archived')]
	public function setArchived(int $id, bool $archived = false): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->toArray($this->service->setArchived($this->userId(), $id, $archived)),
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
	 * Download all responses as a CSV file.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}/entries/export')]
	public function exportEntries(int $id): DataDownloadResponse|JSONResponse {
		try {
			$file = $this->export->csv($this->userId(), $id);
		} catch (NotFoundException) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		$response = new DataDownloadResponse($file['csv'], $file['filename'], 'text/csv; charset=utf-8');
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}

	/**
	 * Download one uploaded file of a response. Always an attachment, so a browser never renders it.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}/entries/{entryId}/files/{fieldId}/{index}')]
	public function downloadFile(int $id, int $entryId, string $fieldId, int $index): StreamResponse|JSONResponse {
		try {
			$file = $this->service->openEntryFile($this->userId(), $id, $entryId, $fieldId, $index);
		} catch (NotFoundException) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		$response = new StreamResponse($file['stream']);
		$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['name']) ?? 'file';
		$response->addHeader('Content-Type', $file['mime'] !== '' ? $file['mime'] : 'application/octet-stream');
		$response->addHeader('Content-Length', (string)$file['size']);
		$response->addHeader('Content-Disposition', 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}

	/**
	 * Email and webhook deliveries for a form, newest first.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}/deliveries')]
	public function deliveries(int $id, string $status = '', int $entryId = 0, int $limit = 50, int $offset = 0): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->actionLog->list($this->userId(), $id, $status === '' ? null : $status, $entryId > 0 ? $entryId : null, $limit, $offset),
		));
	}

	/**
	 * Try a failed delivery again.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/forms/{id}/deliveries/{logId}/retry')]
	public function retryDelivery(int $id, int $logId): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->actionLog->retry($this->userId(), $id, $logId),
		));
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
