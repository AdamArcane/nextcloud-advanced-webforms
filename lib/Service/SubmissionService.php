<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ArcaneX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AdvancedForms\Service;

use OCA\AdvancedForms\Db\Entry;
use OCA\AdvancedForms\Db\EntryMapper;
use OCA\AdvancedForms\Db\Form;
use OCA\AdvancedForms\Db\FormMapper;
use OCA\AdvancedForms\Db\VersionMapper;
use OCA\AdvancedForms\Exception\FormClosedException;
use OCA\AdvancedForms\Exception\LoginRequiredException;
use OCA\AdvancedForms\Exception\NotFoundException;
use OCA\AdvancedForms\Exception\ValidationException;
use OCA\AdvancedForms\Forms\JsonShape;
use OCA\AdvancedForms\Forms\SubmissionValidator;
use OCA\AdvancedForms\Security\FillTimeToken;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\AdvancedForms\Actions\ActionPlanner;
use OCA\AdvancedForms\BackgroundJob\ProcessActionsNowJob;
use OCA\AdvancedForms\Db\ActionLogMapper;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Respondent side: serve a published form and accept submissions
 * (docs/SCHEMA.md section 10), then hand the stored entry to the after-submit
 * actions pipeline.
 */
class SubmissionService {
	public const DEFAULT_CLOSED_MESSAGE = 'This form is no longer accepting responses.';

	private readonly FillTimeToken $tokens;

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
	) {
		$this->tokens = new FillTimeToken($config->getSystemValueString('secret'));
	}

	/**
	 * What the public page needs. `actions` is removed on purpose: it holds
	 * recipient addresses, webhook URLs and secret references.
	 *
	 * @return array<string, mixed>
	 * @throws NotFoundException unpublished or unknown token
	 * @throws FormClosedException
	 * @throws LoginRequiredException
	 */
	public function getPublic(string $token, ?string $userId): array {
		$form = $this->forms->findByToken($token);
		$version = $this->openVersion($form, $userId);
		$definition = $version->getDefinitionArray();
		unset($definition['actions']);

		return [
			'token' => $token,
			'versionId' => $version->getId(),
			'fillToken' => $this->tokens->issue($form->getId(), $version->getId(), $this->time->getTime()),
			'definition' => JsonShape::definition($definition),
		];
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array{message: string|null, redirect: string|null}
	 * @throws NotFoundException
	 * @throws FormClosedException
	 * @throws LoginRequiredException
	 * @throws ValidationException field errors use `field` and `code`; form level errors use only `code`
	 */
	public function submit(
		string $token,
		?string $userId,
		int $versionId,
		array $values,
		string $fillToken,
		string $honeypot,
	): array {
		$form = $this->forms->findByToken($token);
		$latest = $this->openVersion($form, $userId);

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
		$result = $this->validator->validate($definition, $values);
		if ($result['errors'] !== []) {
			throw new ValidationException($result['errors']);
		}

		$settings = is_array($definition['settings'] ?? null) ? $definition['settings'] : [];
		if ($userId !== null && ($settings['allowMultipleSubmissions'] ?? true) === false
			&& $this->entries->countByFormAndUser($form->getId(), $userId) > 0) {
			throw new ValidationException([['code' => 'already_submitted']]);
		}

		$entry = new Entry();
		$entry->setFormId($form->getId());
		$entry->setVersionId($version->getId());
		$entry->setUserId($userId);
		$entry->setData(json_encode($result['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		$entry->setSubmittedAt($this->time->getTime());
		$entry = $this->entries->insert($entry);

		return $this->afterSubmit($form, $definition, $entry, $result['data']);
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
			$this->logger->error('Advanced Forms could not run after-submit actions', ['exception' => $e, 'app' => 'advancedforms']);
			return ['message' => null, 'redirect' => null];
		}
	}

	/**
	 * @throws NotFoundException
	 * @throws FormClosedException
	 * @throws LoginRequiredException
	 */
	private function openVersion(Form $form, ?string $userId): \OCA\AdvancedForms\Db\Version {
		if ($form->getStatus() === Form::STATUS_UNPUBLISHED || $form->getLatestVersionId() === null) {
			throw new NotFoundException();
		}
		$version = $this->versions->find($form->getLatestVersionId());
		$settings = $version->getDefinitionArray()['settings'] ?? [];
		$settings = is_array($settings) ? $settings : [];

		if ($form->getStatus() === Form::STATUS_CLOSED) {
			$message = $settings['closedMessage'] ?? self::DEFAULT_CLOSED_MESSAGE;
			throw new FormClosedException(is_string($message) ? $message : self::DEFAULT_CLOSED_MESSAGE);
		}
		if (($settings['requireLogin'] ?? false) === true && $userId === null) {
			throw new LoginRequiredException();
		}
		return $version;
	}
}
