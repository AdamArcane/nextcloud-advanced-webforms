<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Controller;

use OCA\Webforms\Actions\Ports\TablesFailure;
use OCA\Webforms\Actions\Ports\TablesPort;
use OCA\Webforms\Actions\TablesSchema;
use OCA\Webforms\Actions\TablesValue;
use OCA\Webforms\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * What the builder's Tables action needs to let an owner pick a table and map columns. Everything
 * is read as the signed-in user, so they only ever see tables Tables lets them use.
 */
class TablesController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly TablesPort $tables,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The tables the user may add rows to. `available` is false when Tables is not installed.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/tables')]
	public function index(): JSONResponse {
		if (!$this->tables->isAvailable()) {
			return new JSONResponse(['available' => false, 'tables' => []]);
		}
		try {
			return new JSONResponse(['available' => true, 'tables' => $this->tables->writableTables($this->userId())]);
		} catch (TablesFailure $e) {
			return new JSONResponse(['error' => $e->reason], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * The columns of one table, each with the kind of answer it takes. A column of a type this
	 * action cannot fill (people, relations) has `supported: false`.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/tables/{id}/columns')]
	public function columns(int $id): JSONResponse {
		try {
			$columns = [];
			foreach ($this->tables->columns($id, $this->userId()) as $column) {
				$kind = TablesValue::kind($column);
				$columns[] = $column + ['kind' => $kind, 'supported' => $kind !== null];
			}
			return new JSONResponse(['columns' => $columns]);
		} catch (TablesFailure $e) {
			return new JSONResponse(['error' => $e->reason], $e->reason === 'tables_error' ? Http::STATUS_BAD_GATEWAY : Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Create a Tables table for a form: one optional column per field, in form order, then one for
	 * when the response arrived. The table belongs to the signed-in user. Answers `map`, column id
	 * to source, ready to use as a Tables action (docs/SCHEMA.md section 15.1).
	 *
	 * @param list<array<string, mixed>> $fields the form's fields in order: id, type, label, options
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/tables/from-form')]
	public function createFromForm(string $title = '', array $fields = []): JSONResponse {
		$title = trim($title);
		if ($title === '' || mb_strlen($title) > 255 || count($fields) > TablesSchema::MAX_COLUMNS) {
			return new JSONResponse(['error' => 'tables_bad_request'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		if (!$this->tables->isAvailable()) {
			return new JSONResponse(['error' => 'tables_unavailable'], Http::STATUS_CONFLICT);
		}
		$schema = TablesSchema::forFields(array_values(array_filter($fields, 'is_array')));
		if (count($schema['sources']) < 2) {
			// Only the "submitted" column: a form with no questions has nothing to store.
			return new JSONResponse(['error' => 'tables_no_fields'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		try {
			$created = $this->tables->createTable($title, $schema['columns'], $this->userId());
		} catch (TablesFailure $e) {
			return new JSONResponse(['error' => $e->reason], $e->reason === 'tables_forbidden' ? Http::STATUS_FORBIDDEN : Http::STATUS_BAD_GATEWAY);
		}
		$map = [];
		foreach ($created['columns'] as $index => $column) {
			if (isset($schema['sources'][$index])) {
				$map[(string)$column['id']] = $schema['sources'][$index];
			}
		}
		return new JSONResponse(['tableId' => $created['tableId'], 'title' => $title, 'map' => $map], Http::STATUS_CREATED);
	}

	private function userId(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}
}
