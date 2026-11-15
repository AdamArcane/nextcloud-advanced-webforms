<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Controller;

use OCA\Webforms\AppInfo\Application;
use OCA\Webforms\Forms\DefinitionValidator;
use OCA\Webforms\Security\Captcha\CaptchaProvider;
use OCA\Webforms\Security\Captcha\CaptchaSettings;
use OCA\Webforms\Service\RetentionService;
use OCA\Webforms\Actions\MailPolicy;
use OCA\Webforms\Settings\MailSettings;
use OCA\Webforms\Settings\PolicySettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * Server-wide settings. Without `#[NoAdminRequired]` Nextcloud only lets administrators in.
 */
class AdminController extends Controller {
	private const KEY_PATTERN = '/^[A-Za-z0-9_\-.]{1,200}$/D';

	public function __construct(
		IRequest $request,
		private readonly CaptchaSettings $settings,
		private readonly PolicySettings $policy,
		private readonly MailSettings $mail,
		private readonly RetentionService $retention,
		private readonly IGroupManager $groups,
		private readonly ITimeFactory $time,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/captcha')]
	public function getCaptcha(): JSONResponse {
		return new JSONResponse($this->current());
	}

	/**
	 * @param string|null $secret null or empty keeps the stored secret; it is never sent back
	 */
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/admin/captcha')]
	public function setCaptcha(string $provider = '', string $siteKey = '', ?string $secret = null): JSONResponse {
		$siteKey = trim($siteKey);
		$secret = $secret === null || trim($secret) === '' ? null : trim($secret);
		if (!in_array($provider, CaptchaProvider::ALL, true)) {
			return new JSONResponse(['error' => 'invalid_provider'], Http::STATUS_BAD_REQUEST);
		}
		if (in_array($provider, CaptchaProvider::REMOTE, true)) {
			if (preg_match(self::KEY_PATTERN, $siteKey) !== 1) {
				return new JSONResponse(['error' => 'invalid_site_key'], Http::STATUS_BAD_REQUEST);
			}
			if ($secret === null && $this->settings->secret() === '') {
				return new JSONResponse(['error' => 'secret_required'], Http::STATUS_BAD_REQUEST);
			}
			if ($secret !== null && preg_match(self::KEY_PATTERN, $secret) !== 1) {
				return new JSONResponse(['error' => 'invalid_secret'], Http::STATUS_BAD_REQUEST);
			}
		} else {
			$siteKey = '';
		}
		$this->settings->save($provider, $siteKey, $secret);
		return new JSONResponse($this->current());
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/policy')]
	public function getPolicy(): JSONResponse {
		return new JSONResponse($this->currentPolicy());
	}

	/**
	 * Save the server policy (docs/SCHEMA.md section 16). Turning retention on, or shortening it, would
	 * delete responses that exist today, so it is refused with `confirmation_required` and the number
	 * of responses affected until the request repeats with `confirmDelete`.
	 *
	 * @param list<string> $actionTypes
	 * @param list<string> $creatorGroups
	 */
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/admin/policy')]
	public function setPolicy(
		array $actionTypes = [],
		int $retentionDays = 0,
		int $maxResponses = 0,
		int $maxActions = DefinitionValidator::MAX_ACTIONS,
		array $creatorGroups = [],
		bool $confirmDelete = false,
	): JSONResponse {
		foreach ($actionTypes as $type) {
			if (!is_string($type) || !in_array($type, DefinitionValidator::ACTION_TYPES, true)) {
				return new JSONResponse(['error' => 'invalid_action_type'], Http::STATUS_BAD_REQUEST);
			}
		}
		if ($retentionDays < 0 || $retentionDays > PolicySettings::MAX_RETENTION_DAYS
			|| $maxResponses < 0 || $maxResponses > PolicySettings::MAX_RESPONSES_LIMIT
			|| $maxActions < 1 || $maxActions > DefinitionValidator::MAX_ACTIONS) {
			return new JSONResponse(['error' => 'invalid_limit'], Http::STATUS_BAD_REQUEST);
		}
		if (count($creatorGroups) > PolicySettings::MAX_GROUPS) {
			return new JSONResponse(['error' => 'invalid_group'], Http::STATUS_BAD_REQUEST);
		}
		foreach ($creatorGroups as $group) {
			if (!is_string($group) || !$this->groups->groupExists($group)) {
				return new JSONResponse(['error' => 'unknown_group'], Http::STATUS_BAD_REQUEST);
			}
		}

		$current = $this->policy->retentionDays();
		if ($retentionDays > 0 && ($current === 0 || $retentionDays < $current) && !$confirmDelete) {
			$due = $this->retention->countDue($this->time->getTime(), $retentionDays);
			if ($due > 0) {
				return new JSONResponse(['error' => 'confirmation_required', 'due' => $due], Http::STATUS_CONFLICT);
			}
		}

		$this->policy->save(array_values($actionTypes), $retentionDays, $maxResponses, $maxActions, array_values($creatorGroups));
		return new JSONResponse($this->currentPolicy());
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/mail')]
	public function getMail(): JSONResponse {
		return new JSONResponse($this->currentMail());
	}

	/**
	 * Save the settings for mail that forms send (docs/SCHEMA.md section 16.2).
	 */
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/admin/mail')]
	public function setMail(string $replyToMode = MailPolicy::REPLY_NONE, string $replyToAddress = '', bool $allowOverride = true, string $fromName = ''): JSONResponse {
		if (!in_array($replyToMode, MailPolicy::REPLY_MODES, true)) {
			return new JSONResponse(['error' => 'invalid_reply_to_mode'], Http::STATUS_BAD_REQUEST);
		}
		if ($replyToMode === MailPolicy::REPLY_ADDRESS && !MailSettings::isAddress(trim($replyToAddress))) {
			return new JSONResponse(['error' => 'invalid_reply_to_address'], Http::STATUS_BAD_REQUEST);
		}
		if (mb_strlen(trim($fromName)) > MailPolicy::MAX_NAME || preg_match('/[\x00-\x1F\x7F]/', $fromName) === 1) {
			return new JSONResponse(['error' => 'invalid_from_name'], Http::STATUS_BAD_REQUEST);
		}
		$this->mail->save($replyToMode, $replyToAddress, $allowOverride, $fromName);
		return new JSONResponse($this->currentMail());
	}

	/**
	 * @return array{replyToMode: string, replyToAddress: string, allowOverride: bool, fromName: string}
	 */
	private function currentMail(): array {
		$policy = $this->mail->policy();
		return [
			'replyToMode' => $policy->replyToMode,
			'replyToAddress' => $policy->replyToAddress,
			'allowOverride' => $policy->allowOverride,
			'fromName' => $policy->fromName,
		];
	}

	/**
	 * How many existing responses are older than a number of days, so the page can say what turning
	 * retention on would delete.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/policy/retention-preview')]
	public function retentionPreview(int $days = 0): JSONResponse {
		if ($days < 1 || $days > PolicySettings::MAX_RETENTION_DAYS) {
			return new JSONResponse(['error' => 'invalid_limit'], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['due' => $this->retention->countDue($this->time->getTime(), $days)]);
	}

	/**
	 * Groups to pick creator groups from.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/groups')]
	public function searchGroups(string $search = ''): JSONResponse {
		$found = [];
		foreach ($this->groups->search(trim($search), 25) as $group) {
			$found[] = ['id' => $group->getGID(), 'displayName' => $group->getDisplayName()];
		}
		return new JSONResponse(['groups' => $found]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function currentPolicy(): array {
		$groups = [];
		foreach ($this->policy->creatorGroups() as $id) {
			$groups[] = ['id' => $id, 'displayName' => $this->groups->get($id)?->getDisplayName() ?? $id, 'exists' => $this->groups->groupExists($id)];
		}
		return [
			'actionTypes' => $this->policy->allowedActionTypes(),
			'allActionTypes' => DefinitionValidator::ACTION_TYPES,
			'retentionDays' => $this->policy->retentionDays(),
			'maxResponses' => $this->policy->maxResponsesPerForm(),
			'maxActions' => $this->policy->maxActionsPerForm(),
			'maxActionsCeiling' => DefinitionValidator::MAX_ACTIONS,
			'creatorGroups' => $groups,
		];
	}

	/**
	 * @return array{provider: string, siteKey: string, secretSet: bool}
	 */
	private function current(): array {
		return [
			'provider' => $this->settings->provider(),
			'siteKey' => $this->settings->siteKey(),
			'secretSet' => $this->settings->secret() !== '',
		];
	}
}
