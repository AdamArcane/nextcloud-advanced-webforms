<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Controller;

use OCA\Webforms\Actions\Adapter\NextcloudSecrets;
use OCA\Webforms\AppInfo\Application;
use OCA\Webforms\Exception\CountChangedException;
use OCA\Webforms\Exception\DraftConflictException;
use OCA\Webforms\Exception\ForbiddenException;
use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Service\ActionLogService;
use OCA\Webforms\Forms\EntryFilter;
use OCA\Webforms\Service\EntryExportService;
use OCA\Webforms\Access\Role;
use OCA\Webforms\Service\FormService;
use OCA\Webforms\Service\WaitlistService;
use OCA\Webforms\Forms\ImageSource;
use OCA\Webforms\Forms\JsonShape;
use OCA\Webforms\Import\CoreForms\CoreFormsImporter;
use OCA\Webforms\Uploads\Ports\OwnerImagePort;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
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
		private readonly OwnerImagePort $images,
		private readonly WaitlistService $waitlist,
		private readonly CoreFormsImporter $coreImporter,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * A picture from the caller's own Files, for the builder to show an image block before the form is
	 * published (docs/SCHEMA.md section 4.9). Only the picture types an image block may use.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/image')]
	public function image(string $path = ''): StreamResponse|JSONResponse {
		$clean = ImageSource::filesPath($path);
		$mime = $clean === null ? null : ImageSource::mime($clean);
		$file = $clean === null || $mime === null ? null : $this->images->open($this->userId(), $clean);
		if ($file === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		$response = new StreamResponse($file['stream']);
		$response->addHeader('Content-Type', $mime);
		$response->addHeader('Content-Length', (string)$file['size']);
		$response->addHeader('Content-Disposition', 'inline');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Cache-Control', 'private, max-age=60');
		return $response;
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
			$this->service->toArray($this->service->create($this->userId(), $title), $this->userId(), true),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * A new draft with the same content as this form's draft. `title` names the copy.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/forms/{id}/duplicate')]
	public function duplicate(int $id, string $title = ''): JSONResponse {
		return $this->guard(function () use ($id, $title): JSONResponse {
			$copy = $this->service->duplicateForm($this->userId(), $id, $title);
			return new JSONResponse([
				'form' => $this->service->toArray($copy['form'], $this->userId(), true),
				'errors' => $copy['errors'],
			], Http::STATUS_CREATED);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}')]
	public function show(int $id): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->toArray($this->service->get($this->userId(), $id, Role::REVIEWER), $this->userId(), true),
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
		} catch (ForbiddenException) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
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
	 * Download every form the signed-in user owns as one zip of export files. It sits outside
	 * `/forms/{id}` so it can never be read as a form id.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 5, period: 300)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/backup')]
	public function backup(): DataDownloadResponse|JSONResponse {
		try {
			$file = $this->service->backup($this->userId());
		} catch (\RuntimeException) {
			return new JSONResponse(['error' => 'backup_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$response = new DataDownloadResponse($file['bytes'], $file['filename'], 'application/zip');
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
				'form' => $this->service->toArray($imported['form'], $this->userId(), true),
				// Not blocking for a draft, but publishing will refuse these.
				'errors' => $imported['errors'],
			], Http::STATUS_CREATED);
		});
	}

	/**
	 * The templates the caller can start from: the starter ones that ship with the app, and their own
	 * (docs/SCHEMA.md section 13.4).
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/templates')]
	public function templates(): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse($this->service->listTemplates($this->userId())));
	}

	/**
	 * A new draft form from a template. `strings` maps the English text of a starter template to its
	 * translation; it is ignored for the caller's own templates.
	 *
	 * @param array<mixed> $strings
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 300)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/forms/from-template')]
	public function createFromTemplate(string $templateId = '', string $title = '', array $strings = []): JSONResponse {
		return $this->guard(function () use ($templateId, $title, $strings): JSONResponse {
			$created = $this->service->createFromTemplate($this->userId(), $templateId, $title, $strings);
			return new JSONResponse([
				'form' => $this->service->toArray($created['form'], $this->userId(), true),
				'errors' => $created['errors'],
			], Http::STATUS_CREATED);
		});
	}

	/**
	 * Keep this form's draft as one of the caller's own templates.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/forms/{id}/save-as-template')]
	public function saveAsTemplate(int $id, string $title = ''): JSONResponse {
		return $this->guard(function () use ($id, $title): JSONResponse {
			$template = $this->service->saveAsTemplate($this->userId(), $id, $title);
			return new JSONResponse(['id' => 'u:' . $template->getId(), 'name' => $template->getTitle()], Http::STATUS_CREATED);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/templates/{templateId}')]
	public function deleteTemplate(string $templateId): JSONResponse {
		return $this->guard(function () use ($templateId): JSONResponse {
			$this->service->deleteTemplate($this->userId(), $templateId);
			return new JSONResponse(['ok' => true]);
		});
	}

	/**
	 * The forms the caller owns in the core Forms app, for the import menu (docs/SCHEMA.md section 13.3).
	 * `available` is false when core Forms was never installed here.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/import/core-forms')]
	public function coreForms(): JSONResponse {
		return new JSONResponse($this->coreImporter->listFor($this->userId()));
	}

	/**
	 * Import one of the caller's core Forms forms as a new draft, or with `dryRun` only report what would
	 * be left out. One form per request, so a form with many responses cannot hold up the others.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 300)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/import/core-forms')]
	public function importCoreForm(int $formId = 0, bool $withResponses = false, bool $dryRun = false): JSONResponse {
		return $this->guard(function () use ($formId, $withResponses, $dryRun): JSONResponse {
			$outcome = $this->coreImporter->importOwned($this->userId(), $formId, $withResponses, $dryRun);
			if ($outcome === null) {
				throw new NotFoundException();
			}
			return new JSONResponse($outcome, $dryRun ? Http::STATUS_OK : Http::STATUS_CREATED);
		});
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/forms/{id}/draft')]
	public function saveDraft(int $id, array $definition = [], ?int $baseRevision = null): JSONResponse {
		if ($baseRevision === null) {
			// An out of date client must not overwrite by accident (docs/SCHEMA.md section 22.6).
			return new JSONResponse(['error' => 'base_revision_required'], Http::STATUS_BAD_REQUEST);
		}
		return $this->guard(function () use ($id, $definition, $baseRevision): JSONResponse {
			$saved = $this->service->saveDraft($this->userId(), $id, $definition, $baseRevision);
			return new JSONResponse([
				'form' => $this->service->toArray($saved['form'], $this->userId(), true),
				// Not blocking for a draft, but publishing will refuse these.
				'errors' => $saved['errors'],
			]);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/forms/{id}/publish')]
	public function publish(int $id): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->toArray($this->service->publish($this->userId(), $id), $this->userId(), true),
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/forms/{id}/status')]
	public function setStatus(int $id, int $status = -1): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->toArray($this->service->setStatus($this->userId(), $id, $status), $this->userId()),
		));
	}

	/**
	 * Sharing options: the custom address and embedding (docs/SCHEMA.md section 18).
	 *
	 * @param list<string> $embedOrigins
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/forms/{id}/share')]
	public function setShare(int $id, ?string $slug = null, bool $embed = false, array $embedOrigins = [], ?string $password = null, bool $removePassword = false): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->toArray($this->service->setShare($this->userId(), $id, $slug, $embed, $embedOrigins, $password, $removePassword), $this->userId()),
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/forms/{id}/archived')]
	public function setArchived(int $id, bool $archived = false): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->toArray($this->service->setArchived($this->userId(), $id, $archived), $this->userId()),
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
	public function entries(
		int $id,
		int $limit = 50,
		int $offset = 0,
		string $q = '',
		string $from = '',
		string $to = '',
		string $respondent = '',
		string $where = '',
		string $sort = '',
		string $status = '',
		string $waitlisted = '',
	): JSONResponse {
		try {
			$filter = EntryFilter::fromInput($q, $from, $to, $respondent, $where, $sort, $status, null, $waitlisted);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'filter_invalid', 'reason' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->listEntries($this->userId(), $id, $limit, $offset, $filter),
		));
	}

	/**
	 * What the responses add up to: how many over time, counts per choice, and number ranges. Takes the
	 * same filter as the list.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}/entries/summary')]
	public function entriesSummary(int $id, string $q = '', string $from = '', string $to = '', string $respondent = '', string $where = '', string $status = '', string $waitlisted = ''): JSONResponse {
		try {
			$filter = EntryFilter::fromInput($q, $from, $to, $respondent, $where, 'oldest', $status, null, $waitlisted);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'filter_invalid', 'reason' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		return $this->guard(fn (): JSONResponse => new JSONResponse($this->service->summarizeEntries($this->userId(), $id, $filter)));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/forms/{id}/entries/{entryId}', requirements: ['entryId' => '\d+'])]
	public function destroyEntry(int $id, int $entryId): JSONResponse {
		return $this->guard(function () use ($id, $entryId): JSONResponse {
			$this->service->deleteEntry($this->userId(), $id, $entryId);
			return new JSONResponse([], Http::STATUS_NO_CONTENT);
		});
	}

	/**
	 * Download responses as CSV or an Excel workbook (docs/SCHEMA.md section 17.1): all of them, what a
	 * filter matches (the same parameters as the list), or the ones in `ids`, with all the questions or
	 * only the ones in `columns`.
	 *
	 * @param string $ids comma separated response ids, at most 500, or empty
	 * @param string $columns comma separated field ids, or empty for all questions
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}/entries/export')]
	public function exportEntries(
		int $id,
		string $format = 'csv',
		string $q = '',
		string $from = '',
		string $to = '',
		string $respondent = '',
		string $where = '',
		string $sort = '',
		string $ids = '',
		string $columns = '',
		string $status = '',
		string $waitlisted = '',
	): DataDownloadResponse|JSONResponse {
		if (!in_array($format, [EntryExportService::CSV, EntryExportService::XLSX], true)) {
			return new JSONResponse(['error' => 'format_invalid'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		try {
			$filter = EntryFilter::fromInput($q, $from, $to, $respondent, $where, $sort, $status, null, $waitlisted);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'filter_invalid', 'reason' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$idList = $ids === '' ? null : self::intList($ids, FormService::MAX_BULK_IDS);
		$columnList = $columns === '' ? null : array_values(array_filter(explode(',', $columns), static fn (string $column): bool => preg_match('/^[a-z][a-z0-9_]{2,31}$/D', $column) === 1));
		if (($ids !== '' && $idList === null) || ($columns !== '' && count($columnList ?? []) > 200)) {
			return new JSONResponse(['error' => 'filter_invalid', 'reason' => 'list_invalid'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		try {
			$file = $this->export->export($this->userId(), $id, $filter, $idList, $columnList, $format);
		} catch (NotFoundException) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}
		$response = new DataDownloadResponse($file['body'], $file['filename'], $file['mime']);
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}

	/**
	 * Delete several responses: the ones in `ids`, or everything `filter` matches. A filter must come with
	 * `expected`, the number the person confirmed, and a different number deletes nothing (409).
	 *
	 * @param list<int>|null $ids
	 * @param array<string, mixed> $filter the list parameters: q, from, to, respondent, where, status
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/forms/{id}/entries/bulk-delete')]
	public function bulkDeleteEntries(int $id, ?array $ids = null, array $filter = [], int $expected = -1): JSONResponse {
		if ($ids !== null) {
			foreach ($ids as $one) {
				if (!is_int($one) || $one < 1) {
					return new JSONResponse(['error' => 'ids_invalid'], Http::STATUS_UNPROCESSABLE_ENTITY);
				}
			}
		} elseif ($expected < 0) {
			return new JSONResponse(['error' => 'expected_required'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		try {
			$entryFilter = self::bodyFilter($filter);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'filter_invalid', 'reason' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		try {
			return $this->guard(fn (): JSONResponse => new JSONResponse([
				'deleted' => $this->service->deleteEntries($this->userId(), $id, $ids === null ? null : array_values($ids), $entryFilter, $expected),
			]));
		} catch (CountChangedException $e) {
			return new JSONResponse(['error' => 'count_changed', 'current' => $e->current], Http::STATUS_CONFLICT);
		}
	}

	/**
	 * The filter of a bulk request, which travels in the body with the names of the list parameters.
	 *
	 * @param array<string, mixed> $filter
	 * @throws \InvalidArgumentException
	 */
	private static function bodyFilter(array $filter): EntryFilter {
		$text = static fn (string $key): string => is_scalar($filter[$key] ?? null) ? (string)$filter[$key] : '';
		return EntryFilter::fromInput($text('q'), $text('from'), $text('to'), $text('respondent'), $text('where'), '', $text('status'), null, $text('waitlisted'));
	}

	/**
	 * One response, with the definition it answered and the responses before and after it
	 * (docs/SCHEMA.md section 20.4).
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}/entries/{entryId}', requirements: ['entryId' => '\d+'])]
	public function showEntry(int $id, int $entryId): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse($this->service->getEntry($this->userId(), $id, $entryId)));
	}

	/**
	 * The owner is looking at the list of responses: what arrives from now on is new
	 * (docs/SCHEMA.md section 20.1).
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/forms/{id}/entries/seen')]
	public function markEntriesSeen(int $id): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse([
			'previousSeenAt' => $this->service->markResponsesSeen($this->userId(), $id),
		]));
	}

	/**
	 * @param string|null $status the id of a status, or null for none
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/forms/{id}/entries/{entryId}/status', requirements: ['entryId' => '\d+'])]
	public function setEntryStatus(int $id, int $entryId, ?string $status = null): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->setEntryStatus($this->userId(), $id, $entryId, $status === '' ? null : $status),
		));
	}

	/**
	 * Move a response off the form's waiting list (docs/SCHEMA.md section 3.2).
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/forms/{id}/entries/{entryId}/promote', requirements: ['entryId' => '\d+'])]
	public function promoteEntry(int $id, int $entryId): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->waitlist->promote($this->userId(), $id, $entryId),
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/forms/{id}/entries/{entryId}/note', requirements: ['entryId' => '\d+'])]
	public function setEntryNote(int $id, int $entryId, string $note = ''): JSONResponse {
		return $this->guard(fn (): JSONResponse => new JSONResponse(
			$this->service->setEntryNote($this->userId(), $id, $entryId, $note),
		));
	}

	/**
	 * Give several responses a status: the ones in `ids`, or everything `filter` matches, with the rules
	 * of the bulk delete.
	 *
	 * @param string|null $status the id of a status, or null for none
	 * @param list<int>|null $ids
	 * @param array<string, mixed> $filter the list parameters: q, from, to, respondent, where, status
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/forms/{id}/entries/bulk-status')]
	public function bulkSetEntryStatus(int $id, ?string $status = null, ?array $ids = null, array $filter = [], int $expected = -1): JSONResponse {
		if ($ids !== null) {
			foreach ($ids as $one) {
				if (!is_int($one) || $one < 1) {
					return new JSONResponse(['error' => 'ids_invalid'], Http::STATUS_UNPROCESSABLE_ENTITY);
				}
			}
		} elseif ($expected < 0) {
			return new JSONResponse(['error' => 'expected_required'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		try {
			$entryFilter = self::bodyFilter($filter);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'filter_invalid', 'reason' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		try {
			return $this->guard(fn (): JSONResponse => new JSONResponse([
				'changed' => $this->service->setEntriesStatus($this->userId(), $id, $status === '' ? null : $status, $ids === null ? null : array_values($ids), $entryFilter, $expected),
			]));
		} catch (CountChangedException $e) {
			return new JSONResponse(['error' => 'count_changed', 'current' => $e->current], Http::STATUS_CONFLICT);
		}
	}

	/**
	 * @return list<int>|null null when it is not a list of ids, or has too many
	 */
	private static function intList(string $csv, int $max): ?array {
		$parts = explode(',', $csv);
		if (count($parts) > $max) {
			return null;
		}
		$ids = [];
		foreach ($parts as $part) {
			if (preg_match('/^[1-9][0-9]{0,18}$/D', $part) !== 1) {
				return null;
			}
			$ids[] = (int)$part;
		}
		return $ids;
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
		} catch (ForbiddenException) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
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
			$form = $this->service->get($this->userId(), $id, Role::OWNER);
			$response = new JSONResponse($this->secrets->generate($form->getId(), $ref === '' ? null : $ref), Http::STATUS_CREATED);
			$response->addHeader('Cache-Control', 'no-store');
			return $response;
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/forms/{id}/webhook-secret/{ref}')]
	public function webhookSecretExists(int $id, string $ref): JSONResponse {
		return $this->guard(function () use ($id, $ref): JSONResponse {
			$form = $this->service->get($this->userId(), $id, Role::EDITOR);
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
		} catch (ForbiddenException) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		} catch (DraftConflictException $e) {
			$stored = $e->getStored();
			return new JSONResponse([
				'error' => 'draft_conflict',
				'revision' => $stored->getRevision(),
				'draft' => JsonShape::definition($stored->getDraftDefinition()),
				'savedBy' => $e->getSavedByName(),
				'savedAt' => $stored->getUpdatedAt(),
			], Http::STATUS_CONFLICT);
		} catch (ValidationException $e) {
			return new JSONResponse(['error' => 'validation', 'errors' => $e->getErrors()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}
}
