<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Webforms\Service;

use OCA\Webforms\Db\Entry;
use OCA\Webforms\Db\EntryMapper;
use OCA\Webforms\Db\Form;
use OCA\Webforms\Db\FormMapper;
use OCA\Webforms\Db\VersionMapper;
use OCA\Webforms\Event\EntryCreatedEvent;
use OCA\Webforms\Event\EventPayload;
use OCA\Webforms\Event\EventPublisher;
use OCA\Webforms\Exception\FormClosedException;
use OCA\Webforms\Exception\LoginRequiredException;
use OCA\Webforms\Exception\NotFoundException;
use OCA\Webforms\Exception\PasswordRequiredException;
use OCA\Webforms\Exception\ValidationException;
use OCA\Webforms\Forms\Availability;
use OCA\Webforms\Forms\JsonShape;
use OCA\Webforms\Forms\SubmissionValidator;
use OCA\Webforms\Forms\UploadPolicy;
use OCA\Webforms\Forms\ImageSource;
use OCA\Webforms\Rules\FieldType;
use OCA\Webforms\Security\Captcha\CaptchaService;
use OCA\Webforms\Security\AccessToken;
use OCA\Webforms\Security\FillTimeToken;
use OCA\Webforms\Uploads\UploadRejected;
use OCA\Webforms\Uploads\UploadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Webforms\Actions\ActionPlanner;
use OCA\Webforms\BackgroundJob\ProcessActionsNowJob;
use OCA\Webforms\Db\ActionLogMapper;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\Security\IHasher;
use Psr\Log\LoggerInterface;

/**
 * Respondent side: serve a published form and accept submissions
 * (docs/SCHEMA.md section 10), then hand the stored entry to the after-submit
 * actions pipeline.
 */
class SubmissionService {
	public const DEFAULT_CLOSED_MESSAGE = 'This form is no longer accepting responses.';
	public const FULL_MESSAGE = 'This form has received the maximum number of responses.';
	public const NOT_OPEN_MESSAGE = 'This form is not open yet.';

	private readonly FillTimeToken $tokens;
	private readonly AccessToken $access;

	public function __construct(
		private readonly FormMapper $forms,
		private readonly VersionMapper $versions,
		private readonly EntryMapper $entries,
		private readonly SubmissionValidator $validator,
		private readonly ITimeFactory $time,
		IConfig $config,
		private readonly ActionPlanner $planner,
		private readonly ActionLogMapper $actionLog,
		private readonly IJobList $jobs,
		private readonly LoggerInterface $logger,
		private readonly UploadService $uploads,
		private readonly CaptchaService $captcha,
		private readonly EventPublisher $events,
		private readonly PolicyService $policy,
		private readonly IHasher $hasher,
	) {
		$secret = $config->getSystemValueString('secret');
		$this->tokens = new FillTimeToken($secret);
		$this->access = new AccessToken($secret);
	}

	/**
	 * What the public page needs. `actions` is removed on purpose: it holds
	 * recipient addresses, webhook URLs and secret references.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException unpublished or unknown token
	 * @throws FormClosedException
	 * @throws LoginRequiredException
	 * @throws PasswordRequiredException
	 */
	public function getPublic(string $token, ?string $userId, bool $embedded = false, string $access = ''): array {
		$form = $this->findForm($token, $embedded);
		$userId = $embedded ? null : $userId;
		$remaining = null;
		$version = $this->openVersion($form, $userId, $access, $remaining);
		$definition = $version->getDefinitionArray();
		unset($definition['actions']);
		$captcha = $this->captcha->publicConfig($definition, $userId);
		// Where files end up is the owner's business, not the respondent's.
		foreach ($definition['fields'] as $fieldId => $field) {
			if (is_array($field) && ($field['type'] ?? null) === FieldType::FILE) {
				unset($definition['fields'][$fieldId]['folder'], $definition['fields'][$fieldId]['storage']);
			}
			// Neither is where a picture sits in the owner's Files: the page asks for it by field id.
			if (is_array($field) && ($field['type'] ?? null) === FieldType::IMAGE && ImageSource::kind($field['src'] ?? null) === ImageSource::FILES) {
				$definition['fields'][$fieldId]['src'] = ImageSource::FILES_MARKER;
			}
			// A hidden field's description is the owner's own note about it (docs/SCHEMA.md section 4.7).
			if (is_array($field) && ($field['type'] ?? null) === FieldType::HIDDEN) {
				unset($definition['fields'][$fieldId]['description']);
			}
		}

		return [
			'token' => $token,
			'versionId' => $version->getId(),
			'signedIn' => $userId !== null,
			'uploadLimit' => (int)\OCP\Util::uploadLimit(),
			'fillToken' => $this->tokens->issue($form->getId(), $version->getId(), $this->time->getTime()),
			'captcha' => $captcha,
			'remaining' => $remaining,
			'definition' => JsonShape::definition($definition),
		];
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array{message: string|null, redirect: string|null}
	 * @throws NotFoundException
	 * @throws FormClosedException
	 * @throws LoginRequiredException
	 * @throws PasswordRequiredException
	 * @throws ValidationException field errors use `field` and `code`; form level errors use only `code`
	 */
	public function submit(
		string $token,
		?string $userId,
		int $versionId,
		array $values,
		string $fillToken,
		string $honeypot,
		string $captcha = '',
		string $remoteIp = '',
		bool $embedded = false,
		string $access = '',
	): array {
		$form = $this->findForm($token, $embedded);
		$userId = $embedded ? null : $userId;
		$latest = $this->openVersion($form, $userId, $access);

		$version = $versionId === $latest->getId() ? $latest : $this->versions->find($versionId);
		if ($version->getFormId() !== $form->getId()) {
			throw new NotFoundException();
		}

		// A filled honeypot is a bot. Pretend it worked so it learns nothing.
		if ($honeypot !== '') {
			return ['message' => null, 'redirect' => null];
		}

		$tokenState = $this->tokens->check($fillToken, $form->getId(), $version->getId(), $this->time->getTime());
		if ($tokenState !== 'ok') {
			throw new ValidationException([['code' => 'token_' . $tokenState]]);
		}

		$definition = $version->getDefinitionArray();
		if (!$this->captcha->passes($definition, $userId, $captcha, $remoteIp, $this->time->getTime())) {
			throw new ValidationException([['code' => 'captcha_failed']]);
		}
		$result = $this->validator->validate($definition, $values);
		if ($result['errors'] !== []) {
			throw new ValidationException($result['errors']);
		}

		$settings = is_array($definition['settings'] ?? null) ? $definition['settings'] : [];
		if ($userId !== null && ($settings['allowMultipleSubmissions'] ?? true) === false
			&& $this->entries->countByFormAndUser($form->getId(), $userId) > 0) {
			throw new ValidationException([['code' => 'already_submitted']]);
		}

		$fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
		try {
			$data = $this->uploads->claim($form->getId(), $form->getOwnerId(), $form->getTitle(), $fields, $result['data'], $userId, $this->time->getTime());
		} catch (UploadRejected $e) {
			throw new ValidationException([$e->fieldId === null ? ['code' => $e->reason] : ['field' => $e->fieldId, 'code' => $e->reason]]);
		}

		try {
			$entry = new Entry();
			$entry->setFormId($form->getId());
			$entry->setVersionId($version->getId());
			$entry->setUserId($userId);
			$entry->setData(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			$entry->setSubmittedAt($this->time->getTime());
			$entry = $this->entries->insert($entry);
		} catch (\Throwable $e) {
			// The answers were not saved, so the files must not stay behind.
			$this->uploads->deleteFilesOf($form->getOwnerId(), $fields, $data);
			throw $e;
		}

		// After the commit, and never able to fail it: a Nextcloud event for Flow and other listeners.
		$this->events->publish(static fn (): EntryCreatedEvent => new EntryCreatedEvent(EventPayload::entryCreated(
			$form->getId(),
			$form->getTitle(),
			$form->getOwnerId(),
			$version->getId(),
			$entry->getId(),
			$userId,
			$entry->getSubmittedAt(),
			$fields,
			$data,
		)));

		return $this->afterSubmit($form, $definition, $entry, self::forConditions($fields, $data));
	}

	/**
	 * Action conditions read answers the way rules do, and an upload field reads as the list of file
	 * names (so "has a file" works), not the stored file details.
	 *
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private static function forConditions(array $fields, array $data): array {
		foreach ($data as $fieldId => $value) {
			if (is_array($fields[$fieldId] ?? null) && ($fields[$fieldId]['type'] ?? null) === FieldType::FILE && is_array($value)) {
				$data[$fieldId] = array_values(array_map(static fn (mixed $file): string => is_array($file) && is_string($file['name'] ?? null) ? $file['name'] : '', $value));
			}
		}
		return $data;
	}

	/**
	 * Accept one file for an upload field, before the form is submitted. The browser sends the
	 * file by itself so the submit request stays small; the answer to the field is the id it gets back.
	 *
	 * @param array{name?: string, tmp_name?: string, error?: int, size?: int}|null $file PHP's entry for the upload
	 * @return array{id: string, name: string, size: int}
	 * @throws NotFoundException
	 * @throws FormClosedException
	 * @throws LoginRequiredException
	 * @throws ValidationException
	 */
	public function upload(string $token, ?string $userId, int $versionId, string $fieldId, string $fillToken, ?array $file, bool $embedded = false, string $access = ''): array {
		$form = $this->findForm($token, $embedded);
		$userId = $embedded ? null : $userId;
		$latest = $this->openVersion($form, $userId, $access);
		$version = $versionId === $latest->getId() ? $latest : $this->versions->find($versionId);
		if ($version->getFormId() !== $form->getId()) {
			throw new NotFoundException();
		}
		// Unlike a submission, an upload may follow the page load at once.
		$tokenState = $this->tokens->check($fillToken, $form->getId(), $version->getId(), $this->time->getTime());
		if ($tokenState === 'invalid' || $tokenState === 'expired') {
			throw new ValidationException([['code' => 'token_' . $tokenState]]);
		}
		$fields = $version->getDefinitionArray()['fields'] ?? [];
		$field = is_array($fields) ? ($fields[$fieldId] ?? null) : null;
		if (!is_array($field)) {
			throw new NotFoundException();
		}
		if ($file === null || !isset($file['tmp_name'], $file['name'])) {
			throw new ValidationException([['field' => $fieldId, 'code' => 'upload_failed']]);
		}
		$error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
		if ($error !== UPLOAD_ERR_OK) {
			$code = $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE ? 'upload_too_large' : 'upload_failed';
			throw new ValidationException([['field' => $fieldId, 'code' => $code]]);
		}
		try {
			return $this->uploads->receive($form->getId(), $fieldId, $field, $userId, (string)$file['name'], (string)$file['tmp_name'], (int)($file['size'] ?? 0), $this->time->getTime());
		} catch (UploadRejected $e) {
			throw new ValidationException([['field' => $fieldId, 'code' => $e->reason]]);
		}
	}

	/**
	 * Run the after-submit actions (docs/SCHEMA.md 7.4). The entry is already
	 * stored, so nothing here may fail the submission: a problem is logged and
	 * the respondent still sees success.
	 *
	 * @param array<string, mixed> $definition
	 * @param array<string, mixed> $data
	 * @return array{message: string|null, redirect: string|null}
	 */
	private function afterSubmit(Form $form, array $definition, Entry $entry, array $data): array {
		try {
			// Actions of a type the server no longer allows do not run, even on a form that is already live.
			$definition = $this->policy->withoutDisabledActions($definition);
			$plan = $this->planner->plan($definition, $data);

			$types = [];
			foreach (is_array($definition['actions'] ?? null) ? $definition['actions'] : [] as $action) {
				if (is_array($action) && is_string($action['id'] ?? null) && is_string($action['type'] ?? null)) {
					$types[$action['id']] = $action['type'];
				}
			}
			foreach ($plan['queue'] as $actionId) {
				$this->actionLog->enqueue($form->getId(), $entry->getId(), $actionId, $types[$actionId] ?? '', $this->time->getTime());
			}
			if ($plan['queue'] !== []) {
				$this->jobs->add(ProcessActionsNowJob::class);
			}

			return ['message' => $plan['message'], 'redirect' => $plan['redirect']];
		} catch (\Throwable $e) {
			$this->logger->error('Advanced Webforms could not run after-submit actions', ['exception' => $e, 'app' => 'webforms']);
			return ['message' => null, 'redirect' => null];
		}
	}

	/**
	 * The form behind a public address. An embedded request (docs/SCHEMA.md section 18.3) comes from a
	 * frame on another website: it is only answered for a form that allows embedding, and its caller is
	 * always treated as anonymous, because those endpoints do without the request token.
	 *
	 * @throws NotFoundException
	 */
	/**
	 * The picture of an image block that comes from the owner's Files, for whoever may open the form
	 * right now: the same checks as getPublic(), so a closed or unpublished form gives nothing away.
	 *
	 * @return array{ownerId: string, path: string}
	 * @throws NotFoundException
	 * @throws FormClosedException
	 * @throws LoginRequiredException
	 */
	public function imageSource(string $token, ?string $userId, string $fieldId, bool $embedded = false, string $access = ''): array {
		$form = $this->findForm($token, $embedded);
		$version = $this->openVersion($form, $embedded ? null : $userId, $access);
		$field = $version->getDefinitionArray()['fields'][$fieldId] ?? null;
		$src = is_array($field) && ($field['type'] ?? null) === FieldType::IMAGE ? ($field['src'] ?? null) : null;
		$path = is_string($src) && ImageSource::kind($src) === ImageSource::FILES ? ImageSource::filesPath($src) : null;
		if ($path === null) {
			throw new NotFoundException();
		}
		return ['ownerId' => $form->getOwnerId(), 'path' => $path];
	}

	/**
	 * Trade the password of a form's link for an access token that opens it for a while
	 * (docs/SCHEMA.md section 18.5). A form with no password gives an empty token, which it does not need.
	 *
	 * @throws NotFoundException unpublished or unknown address
	 * @throws ValidationException `wrong_password`
	 */
	public function unlock(string $token, string $password, bool $embedded = false): string {
		$form = $this->findForm($token, $embedded);
		if ($form->getStatus() === Form::STATUS_UNPUBLISHED || $form->getLatestVersionId() === null) {
			throw new NotFoundException();
		}
		$hash = $form->getLinkPassword();
		if ($hash === null || $hash === '') {
			return '';
		}
		$newHash = '';
		if ($password === '' || !$this->hasher->verify($password, $hash, $newHash)) {
			throw new ValidationException([['code' => 'wrong_password']]);
		}
		if ($newHash !== '') {
			// The hashing settings moved on since the password was set. Keep it current, which keeps tokens valid.
			$form->setLinkPassword($newHash);
			$this->forms->update($form);
			$hash = $newHash;
		}
		return $this->access->issue($form->getId(), $hash, $this->time->getTime());
	}

	/**
	 * @throws PasswordRequiredException
	 */
	private function requireAccess(Form $form, string $access): void {
		$hash = $form->getLinkPassword();
		if ($hash === null || $hash === '') {
			return;
		}
		if (!$this->access->check($access, $form->getId(), $hash, $this->time->getTime())) {
			throw new PasswordRequiredException();
		}
	}

	private function findForm(string $token, bool $embedded): Form {
		$form = $this->forms->findByToken($token);
		if ($embedded && !$form->isEmbeddable()) {
			throw new NotFoundException();
		}
		return $form;
	}

	/**
	 * The version respondents get, or the reason they get none: closed by the owner, outside the form's
	 * dates, or full (docs/SCHEMA.md sections 3.1 and 16).
	 *
	 * @param int|null $remaining set to how many more responses the form takes, when it is set to show that
	 * @throws NotFoundException
	 * @throws FormClosedException
	 * @throws LoginRequiredException
	 * @throws PasswordRequiredException
	 */
	private function openVersion(Form $form, ?string $userId, string $access, ?int &$remaining = null): \OCA\Webforms\Db\Version {
		if ($form->getStatus() === Form::STATUS_UNPUBLISHED || $form->getLatestVersionId() === null) {
			throw new NotFoundException();
		}
		// Before anything else is said about the form, closed or full included (docs/SCHEMA.md section 18.5).
		$this->requireAccess($form, $access);
		$version = $this->versions->find($form->getLatestVersionId());
		$settings = $version->getDefinitionArray()['settings'] ?? [];
		$settings = is_array($settings) ? $settings : [];

		if ($form->getStatus() === Form::STATUS_CLOSED || $form->isArchived()) {
			throw new FormClosedException(self::message($settings, 'closedMessage', self::DEFAULT_CLOSED_MESSAGE));
		}

		$policyCap = $this->policy->settings()->maxResponsesPerForm();
		// Counting is only needed when something limits the number.
		$limited = $policyCap > 0 || Availability::ownCap($settings) > 0;
		$availability = Availability::of($settings, $this->time->getTime(), $limited ? $this->entries->countByForm($form->getId()) : 0, $policyCap);
		switch ($availability['state']) {
			case Availability::ENDED:
				throw new FormClosedException(self::message($settings, 'closedMessage', self::DEFAULT_CLOSED_MESSAGE));
			case Availability::SCHEDULED:
				throw new FormClosedException(self::message($settings, 'notOpenMessage', self::NOT_OPEN_MESSAGE), FormClosedException::NOT_OPEN);
			case Availability::FULL:
				throw new FormClosedException(self::message($settings, 'fullMessage', self::FULL_MESSAGE), FormClosedException::FULL);
		}

		if (($settings['requireLogin'] ?? false) === true && $userId === null) {
			throw new LoginRequiredException();
		}
		if (($settings['showRemaining'] ?? false) === true && Availability::ownCap($settings) > 0) {
			$remaining = $availability['cap'] - $availability['responses'];
		}
		return $version;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function message(array $settings, string $key, string $default): string {
		$message = $settings[$key] ?? null;
		return is_string($message) && trim($message) !== '' ? $message : $default;
	}
}
