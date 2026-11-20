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
use OCA\Webforms\Forms\EntryFilter;
use OCA\Webforms\Service\ApiService;
use OCA\Webforms\Settings\PolicySettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The read-only API for other software (docs/SCHEMA.md section 23), under /ocs/v2.php/apps/webforms/api/v1.
 * Every route is a GET: nothing here creates, changes or deletes. Signed in with an app password, the
 * caller sees what their role on a form allows (ApiService), and the whole API answers 404 when the
 * administrator has switched it off.
 */
class ApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private readonly ApiService $api,
		private readonly IUserSession $userSession,
		private readonly PolicySettings $policy,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The forms the caller can open.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/forms')]
	public function forms(): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(['forms' => $this->api->forms($this->userId())]));
	}

	/**
	 * One form with its latest published questions.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/forms/{id}', requirements: ['id' => '\d+'])]
	public function form(int $id): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->api->form($this->userId(), $id)));
	}

	/**
	 * The published versions of a form.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/forms/{id}/versions', requirements: ['id' => '\d+'])]
	public function versions(int $id): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(['versions' => $this->api->versions($this->userId(), $id)]));
	}

	/**
	 * One version's questions: what each field id meant when its responses were given.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/forms/{id}/versions/{versionId}', requirements: ['id' => '\d+', 'versionId' => '\d+'])]
	public function version(int $id, int $versionId): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->api->version($this->userId(), $id, $versionId)));
	}

	/**
	 * Responses. `after` returns only responses with a larger id, oldest first, and `nextAfter` in the answer
	 * is the value to send next time (docs/SCHEMA.md section 23.2).
	 *
	 * @param int $limit 1 to 200
	 * @param int $after only responses with a larger id
	 * @param string $sort `newest` (default) or `oldest`
	 * @param string $from a date, YYYY-MM-DD, UTC
	 * @param string $to a date, YYYY-MM-DD, UTC
	 * @param string $status comma separated status ids, or `none`
	 * @param string $respondent `any`, `signed_in` or `anonymous`
	 * @param string $q a text to search for
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/forms/{id}/responses', requirements: ['id' => '\d+'])]
	public function responses(int $id, int $limit = 50, int $after = 0, string $sort = '', string $from = '', string $to = '', string $status = '', string $respondent = '', string $q = ''): DataResponse {
		return $this->answer(function () use ($id, $limit, $after, $sort, $from, $to, $status, $respondent, $q): DataResponse {
			$filter = $this->filter($q, $from, $to, $respondent, $after > 0 ? 'oldest' : $sort, $status, $after);
			return new DataResponse($this->api->responses($this->userId(), $id, $limit, $filter));
		});
	}

	/**
	 * One response.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/forms/{id}/responses/{responseId}', requirements: ['id' => '\d+', 'responseId' => '\d+'])]
	public function response(int $id, int $responseId): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->api->response($this->userId(), $id, $responseId)));
	}

	/**
	 * Counts per choice, number ranges and responses per day, over the responses the filters match.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/forms/{id}/summary', requirements: ['id' => '\d+'])]
	public function summary(int $id, string $from = '', string $to = '', string $status = '', string $respondent = '', string $q = ''): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->api->summary($this->userId(), $id, $this->filter($q, $from, $to, $respondent, 'oldest', $status))));
	}

	/**
	 * The responses the filters match, as a CSV file.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/forms/{id}/responses/export', requirements: ['id' => '\d+'])]
	public function export(int $id, string $from = '', string $to = '', string $status = '', string $respondent = '', string $q = '', string $format = 'csv'): DataResponse|DataDownloadResponse {
		return $this->answer(function () use ($id, $from, $to, $status, $respondent, $q, $format): DataResponse|DataDownloadResponse {
			if ($format !== 'csv') {
				return new DataResponse(['error' => 'format_invalid'], Http::STATUS_UNPROCESSABLE_ENTITY);
			}
			$file = $this->api->csv($this->userId(), $id, $this->filter($q, $from, $to, $respondent, 'oldest', $status));
			$response = new DataDownloadResponse($file['body'], $file['filename'], $file['mime']);
			$response->addHeader('Cache-Control', 'no-store');
			return $response;
		});
	}

	private function userId(): string {
		// #[NoAdminRequired] still requires a signed in account, so this is set.
		return (string)$this->userSession->getUser()?->getUID();
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function filter(string $q, string $from, string $to, string $respondent, string $sort, string $status, int $after = 0): EntryFilter {
		return EntryFilter::fromInput($q, $from, $to, $respondent, '', $sort, $status, $after > 0 ? $after : null);
	}

	/**
	 * Runs a read, answering 404 when the API is switched off or the form cannot be opened, and 422 for a
	 * filter that does not make sense.
	 *
	 * @param callable(): (DataResponse|DataDownloadResponse) $read
	 */
	private function answer(callable $read): DataResponse|DataDownloadResponse {
		if (!$this->policy->apiEnabled()) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		try {
			return $read();
		} catch (NotFoundException|ForbiddenException) {
			// A form the caller may not open is not found: the API does not say whether it exists.
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => 'filter_invalid', 'reason' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}
}
